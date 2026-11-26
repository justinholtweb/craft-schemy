<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Ui\Contracts\Node;
use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\Scope;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Helpers\PropertyTable;
use JustinHoltWeb\Schemy\Plugin;
use JustinHoltWeb\Schemy\Services\Schema;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Section and entry type schema, for users with the `schemy-editSections` permission (see
 * `routes/cp.php`). Stored in Schemy's table, not project config, so it can be edited in any
 * environment.
 */
readonly class SectionsController
{
    use RespondsWithFlash;

    public function __construct(
        private Schema $schema,
    ) {}

    public function index(): CpScreenResponse
    {
        $groups = [
            SectionType::Single->value => t('Single'),
            SectionType::Channel->value => t('Channel'),
            SectionType::Structure->value => t('Structure'),
        ];
        $rows = [];

        foreach (Sections::getAllSections() as $section) {
            if (! Plugin::canManageSection(currentUser(), $section)) {
                continue;
            }

            $config = $this->schema->getSectionConfig($section);

            $rows[] = [
                'id' => $section->id,
                'name' => ['label' => (string) $section->name, 'url' => cp_url("schemy/sections/{$section->id}")],
                'sectionType' => $groups[$section->type?->value] ?? '',
                'schemaType' => $config['type'] !== '' ? $config['type'] : '—',
                'overrides' => count($config['entryTypes']) ?: '—',
            ];
        }

        $table = Table::make('schemy-sections')
            ->columns([
                ['key' => 'name', 'label' => t('Section')],
                ['key' => 'sectionType', 'label' => t('Type')],
                ['key' => 'schemaType', 'label' => t('Schema type', category: 'schemy')],
                ['key' => 'overrides', 'label' => t('Entry type overrides', category: 'schemy')],
            ])
            ->rows($rows)
            ->emptyMessage(t('No sections exist yet.', category: 'schemy'));

        return new CpScreenResponse()
            ->title(t('Section Schema', category: 'schemy'))
            ->ui(Ui::make([$table]));
    }

    public function edit(int $sectionId): CpScreenResponse
    {
        $section = $this->section($sectionId);
        $config = $this->schema->getSectionConfig($section);
        $values = [
            'type' => $config['type'],
            'properties' => $config['properties'],
            'entryTypes' => [],
        ];

        foreach ($section->getEntryTypes() as $entryType) {
            $entryTypeConfig = $config['entryTypes'][$entryType->uid] ?? null;
            $values['entryTypes'][$entryType->uid] = [
                'override' => $entryTypeConfig !== null,
                'type' => $entryTypeConfig['type'] ?? '',
                'properties' => $entryTypeConfig['properties'] ?? [],
            ];
        }

        return $this->screen($section, $values, $config['untrusted']);
    }

    public function save(Request $request, int $sectionId): Response|CpScreenResponse
    {
        $section = $this->section($sectionId);
        $posted = [
            'type' => $request->input('type'),
            'properties' => (array) $request->input('properties', []),
            'entryTypes' => (array) $request->input('entryTypes', []),
        ];

        if (! $this->schema->saveSectionConfig($section, $posted, self::vouch($request))) {
            return $this->asFailure(t('Couldn’t save schema settings.', category: 'schemy'));
        }

        return $this->asSuccess(
            t('Schema saved for {section}.', ['section' => $section->name], category: 'schemy'),
            redirect: cp_url("schemy/sections/{$section->id}"),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function screen(Section $section, array $values, bool $untrusted): CpScreenResponse
    {
        $entryTypes = $section->getEntryTypes();
        $fieldOptions = $this->schema->fieldOptions(array_map(fn (EntryType $entryType) => $entryType->getFieldLayout(), $entryTypes));

        $nodes = [];

        if ($untrusted) {
            array_push($nodes, ...SectionsController::untrustedNodes());
        }

        $nodes[] = Field::make(t('Schema type', category: 'schemy'), Combobox::make('type')->options(PropertyTable::typeOptions())->placeholder('Article'))
            ->instructions(t('The schema.org type every entry in this section is described as. Leave blank to output no main entity. Any schema.org type works; the suggestions are the ones Schemy knows.', category: 'schemy'));
        $nodes[] = Field::make(t('Properties', category: 'schemy'), PropertyTable::make('properties', [], $fieldOptions, $values['type'] ?: null))
            ->instructions(t('Map each property to a field (or `title`, `url`, `postDate`, `author`…) or to a template such as `{title}` or `{{ entry.summary }}`. Dot paths like `address.streetAddress` build nested objects; repeating a property makes a list.', category: 'schemy'));

        if ($entryTypes !== []) {
            $nodes[] = Heading::make('schemy-entry-types', t('Entry types', category: 'schemy'))
                ->description(t('Entry types use the section’s schema unless you override it here. Craft shares entry types across sections, so an override only applies in this section.', category: 'schemy'));
        }

        foreach ($entryTypes as $entryType) {
            $entryTypeFieldOptions = $this->schema->fieldOptions([$entryType->getFieldLayout()]);
            $entryTypeValues = $values['entryTypes'][$entryType->uid] ?? [];

            $nodes[] = Scope::make(['entryTypes', (string) $entryType->uid], [
                Field::make(t('{entryType}: override the section’s schema', ['entryType' => $entryType->name], category: 'schemy'), Lightswitch::make('override')),
                Field::make(t('Schema type', category: 'schemy'), Combobox::make('type')->options(PropertyTable::typeOptions())->placeholder('NewsArticle')),
                Field::make(t('Properties', category: 'schemy'), PropertyTable::make('properties', [], $entryTypeFieldOptions, ($entryTypeValues['type'] ?? '') ?: null)),
            ]);
        }

        return new CpScreenResponse()
            ->title(t('{section} Schema', ['section' => $section->name], category: 'schemy'))
            ->crumbs([
                new ActionItem()->label(t('Section Schema', category: 'schemy'))->href(cp_url('schemy/sections')),
                new ActionItem()->label((string) $section->name),
            ])
            ->ui(Ui::make($nodes), new UiContext(values: $values), props: [
                'submit' => ['method' => 'post', 'url' => cp_url("schemy/sections/{$section->id}")],
            ]);
    }

    /**
     * The note on a page holding values saved by someone who isn't an admin, and for admins, the
     * switch that vouches for them.
     *
     * @return list<Node>
     */
    public static function untrustedNodes(): array
    {
        $nodes = [
            Callout::make('schemy-untrusted', t('Values here were saved by someone who isn’t an admin, so Twig in them isn’t run: they’re output as text, with only `{{ siteName }}` and `{{ siteUrl }}` filled in. Use the Field source for entry values.', category: 'schemy')),
        ];

        if (currentUser()?->isAdmin()) {
            $nodes[] = Field::make(t('Run these values as Twig', category: 'schemy'), Lightswitch::make('trustTwig'))
                ->instructions(t('Check every value first: Twig can read and publish anything the site can.', category: 'schemy'));
        }

        return $nodes;
    }

    /**
     * True when an admin switched on "Run these values as Twig". Null leaves it to the signed-in user.
     */
    public static function vouch(Request $request): ?bool
    {
        return currentUser()?->isAdmin() && $request->boolean('trustTwig') ? true : null;
    }

    private function section(int $sectionId): Section
    {
        $section = Sections::getSectionById($sectionId);
        abort_if($section === null, 404, 'Section not found');
        // Mapped field values are published, so the user must be able to view the entries
        abort_unless(Plugin::canManageSection(currentUser(), $section), 403);

        return $section;
    }
}
