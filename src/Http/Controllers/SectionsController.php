<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Http\Controllers;

use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\Scope;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Helpers\PropertyTable;
use JustinHoltWeb\Schemy\Services\Schema;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Section and entry type schema. Admin-only; saving also needs `allowAdminChanges`, and the
 * screens are read-only without it.
 */
readonly class SectionsController
{
    use RespondsWithFlash;

    public function __construct(
        private Schema $schema,
        private GeneralConfig $generalConfig,
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

        return $this->screen($section, $values);
    }

    public function save(Request $request, int $sectionId): Response|CpScreenResponse
    {
        $section = $this->section($sectionId);
        $posted = [
            'type' => $request->input('type'),
            'properties' => (array) $request->input('properties', []),
            'entryTypes' => (array) $request->input('entryTypes', []),
        ];

        if (! $this->schema->saveSectionConfig($section, $posted)) {
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
    private function screen(Section $section, array $values): CpScreenResponse
    {
        $editable = $this->generalConfig->allowAdminChanges;
        $entryTypes = $section->getEntryTypes();
        $fieldOptions = $this->schema->fieldOptions(array_map(fn (EntryType $entryType) => $entryType->getFieldLayout(), $entryTypes));

        $nodes = [];

        if (! $editable) {
            $nodes[] = Callout::make('schemy-readonly', t('Schemy settings are stored in project config, and `allowAdminChanges` is disabled in this environment. Make changes in your development environment and deploy them.', category: 'schemy'))
                ->variant('warning');
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
            ->ui(Ui::make($nodes), new UiContext(
                values: $values,
                mode: $editable ? ControlMode::Editable : ControlMode::ReadOnly,
            ), props: [
                'readOnly' => ! $editable,
                'submit' => $editable ? ['method' => 'post', 'url' => cp_url("schemy/sections/{$section->id}")] : null,
            ]);
    }

    private function section(int $sectionId): Section
    {
        $section = Sections::getSectionById($sectionId);
        abort_if($section === null, 404, 'Section not found');

        return $section;
    }
}
