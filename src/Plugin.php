<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons as CpIcons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Twig\Events\PageEnded;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Contracts\CraftUser;
use CraftCms\Cms\User\Data\Permission;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Schemy\Console\Commands\ExportSchemasCommand;
use JustinHoltWeb\Schemy\Console\Commands\ImportSchemasCommand;
use JustinHoltWeb\Schemy\Console\Commands\ValidateCommand;
use JustinHoltWeb\Schemy\Fields\SchemaField;
use JustinHoltWeb\Schemy\Gql\SchemyQuery;
use JustinHoltWeb\Schemy\Listeners\AddEditorMetadata;
use JustinHoltWeb\Schemy\Listeners\InjectJsonLd;
use JustinHoltWeb\Schemy\Mcp\SchemyTools;
use JustinHoltWeb\Schemy\Models\Settings;
use JustinHoltWeb\Schemy\Services\Store;
use JustinHoltWeb\Schemy\Services\Validator;
use JustinHoltWeb\Schemy\Twig\SchemyVariable;
use JustinHoltWeb\Schemy\Utilities\ValidatorUtility;
use Override;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Schemy: schema.org JSON-LD for sections, entry types and entries.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Edit section and entry type schema (and see the Sections screen). Same as Schemy 5.2. */
    public const string PERMISSION_EDIT_SECTIONS = 'schemy-editSections';

    /** Edit the site identity node. Same as Schemy 5.2. */
    public const string PERMISSION_EDIT_IDENTITY = 'schemy-editIdentity';

    /** Matches Schemy 5.2: section schema and the identity in the `schemy_schemas` table. */
    public string $schemaVersion = '1.1.0';

    /** Sites upgrade from the last Schemy 5 release, which has the same table and project config keys. */
    public string $minVersionRequired = '5.2.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    /** The validator utility's severity badges. */
    protected array $styles = [
        __DIR__.'/../resources/css/cp.css' => 'cp.css',
    ];

    protected array $fieldTypes = [
        SchemaField::class,
    ];

    protected array $utilities = [
        ValidatorUtility::class,
    ];

    protected array $gqlQueries = [
        SchemyQuery::class,
    ];

    protected array $commands = [
        ValidateCommand::class,
        ExportSchemasCommand::class,
        ImportSchemasCommand::class,
    ];

    protected array $events = [
        PageEnded::class => InjectJsonLd::class,
        ElementMetadataResolving::class => AddEditorMetadata::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(Validator::class);
        $this->app->scoped(Store::class);
    }

    public function boot(CapabilityRegistry $capabilities): void
    {
        // `craft.schemy` in Twig, `$craft->schemy` in Blade
        CraftVariable::macro('schemy', fn () => app(SchemyVariable::class));

        Event::listen(function (GqlSchemaComponentsResolving $event): void {
            $event->queries[t('Schemy', category: 'schemy')] = [
                SchemyQuery::COMPONENT.':read' => [
                    'label' => t('Query JSON-LD with the `schemy` query', category: 'schemy'),
                    // Results are limited to entries the schema's user can view
                    'authenticated' => true,
                ],
            ];
        });

        $capabilities->register(SchemyTools::class);
    }

    /**
     * Craft lists these under Schemy in user and group permissions, after "Access Schemy".
     *
     * @return list<Permission>
     */
    #[Override]
    protected function getPermissions(): array
    {
        $warning = t('Mapped field values are published in each page’s JSON-LD. Twig in values saved by non-admins isn’t run until an admin vouches for it. Sections are limited to those the user can view entries in.', category: 'schemy');

        return [
            new Permission(self::PERMISSION_EDIT_SECTIONS, t('Edit section and entry type schema', category: 'schemy'), warning: $warning),
            new Permission(self::PERMISSION_EDIT_IDENTITY, t('Edit site identity', category: 'schemy'), warning: $warning),
        ];
    }

    /**
     * Whether the user can manage a section's schema: Schemy's permission plus permission to
     * view the section's entries, since mapped field values are published in the JSON-LD.
     */
    public static function canManageSection(?CraftUser $user, Section $section): bool
    {
        if ($user === null) {
            return false;
        }

        $gate = Gate::forUser($user);

        return $gate->allows(self::PERMISSION_EDIT_SECTIONS) && $gate->allows("viewEntries:$section->uid");
    }

    /**
     * Craft only shows this to users with "Access Schemy". Each screen also needs its own
     * permission, and the settings screen is admin-only.
     */
    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $user = currentUser();

        if ($user === null) {
            return null;
        }

        $gate = Gate::forUser($user);
        $sections = $gate->allows(self::PERMISSION_EDIT_SECTIONS);
        $identity = $gate->allows(self::PERMISSION_EDIT_IDENTITY);

        // The validator alone isn't a reason to show Schemy; it has its own place in Utilities
        if (! $sections && ! $identity && ! $user->isAdmin()) {
            return null;
        }

        $subnav = [];

        if ($sections) {
            $subnav[] = new NavItem()->label(t('Sections', category: 'schemy'))->href('schemy/sections');
        }

        if ($identity) {
            $subnav[] = new NavItem()->label(t('Site Identity', category: 'schemy'))->href('schemy/identity');
        }

        if ($gate->allows('utility:'.ValidatorUtility::id())) {
            $subnav[] = new NavItem()->label(t('Validator', category: 'schemy'))->href('utilities/'.ValidatorUtility::id());
        }

        if ($user->isAdmin()) {
            $subnav[] = new NavItem()->label(t('Settings', category: 'schemy'))->href('settings/plugins/schemy');
        }

        $item = parent::getCpNavItem();

        if (! $item instanceof NavItem) {
            return $item;
        }

        $item->subnav($subnav);

        return $this->customiseNavItem($item, $this->getSettings());
    }

    /**
     * The sidebar label and icon from settings: label, then pasted SVG (sanitized every time it's
     * drawn), then an icon name. Craft's own Settings → Control Panel Navigation overrides win,
     * because Craft applies them afterwards. A bad value never breaks the nav.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitized = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitized !== '') {
                    return $item->iconSvg($sanitized)->icon(null);
                }
            }

            if ($icon !== '' && IconSets::parse($icon) !== null) {
                // A custom set's icon (`set:name`) is drawn server-side
                return $item->iconSvg(CpIcons::svg($icon))->icon(null);
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                return $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable $e) {
            Log::warning('Schemy couldn’t draw the custom nav icon: '.$e->getMessage());
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        return Ui::make([
            Heading::make('schemy-output', t('Output', category: 'schemy')),
            Field::make(t('Output automatically', category: 'schemy'), Lightswitch::make('autoInject'))
                ->instructions(t('Add JSON-LD to the `<head>` of every front-end page. Turn this off to place `{{ craft.schemy.render() }}` in your templates yourself. Blade layouts need `@craftHead` for automatic output.', category: 'schemy')),
            Field::make(t('Include site identity', category: 'schemy'), Lightswitch::make('includeIdentity'))
                ->instructions(t('Output the Organization/Person node configured under Site Identity.', category: 'schemy')),
            Field::make(t('Include WebSite', category: 'schemy'), Lightswitch::make('includeWebSite'))
                ->instructions(t('Output a WebSite node for the current site.', category: 'schemy')),
            Field::make(t('Include breadcrumbs', category: 'schemy'), Lightswitch::make('includeBreadcrumbs'))
                ->instructions(t('Output a BreadcrumbList built from the site home page and, in structure sections, the entry’s ancestors.', category: 'schemy')),

            Heading::make('schemy-nav', t('Control panel', category: 'schemy'))
                ->description(t('How Schemy appears in the sidebar. An override set in Settings → Control Panel Navigation takes precedence over these.', category: 'schemy')),
            Field::make(t('Sidebar label', category: 'schemy'), Text::make('navLabel')->placeholder($this->name))
                ->instructions(t('Leave empty for “{name}”.', ['name' => $this->name], category: 'schemy')),
            Field::make(t('Sidebar icon', category: 'schemy'), IconPicker::make('navIcon')->sets('*'))
                ->instructions(t('Leave empty for Schemy’s own icon.', category: 'schemy')),
            Field::make(t('Custom SVG icon', category: 'schemy'), Textarea::make('navIconSvg')->rows(4)->monospace())
                ->instructions(t('Optional. Paste one `<svg>` element to use instead of the icon above. It’s sanitized every time it’s drawn; a single-color icon using `currentColor` matches the rest of the sidebar.', category: 'schemy')),
        ]);
    }
}
