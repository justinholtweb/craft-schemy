<?php

namespace justinholtweb\schemy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\services\Gql;
use craft\services\Mcp;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\schemy\fields\SchemaField;
use justinholtweb\schemy\gql\queries\SchemyQuery;
use justinholtweb\schemy\mcp\SchemyTools;
use justinholtweb\schemy\models\Settings;
use justinholtweb\schemy\services\Schema;
use justinholtweb\schemy\services\Validator;
use justinholtweb\schemy\utilities\ValidatorUtility;
use justinholtweb\schemy\variables\SchemyVariable;
use Throwable;
use yii\base\Event;

/**
 * Schemy: schema.org JSON-LD for sections, entry types, and entries.
 *
 * @property-read Schema $schema
 * @property-read Validator $validator
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'schema' => Schema::class,
                'validator' => Validator::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SchemaField::class;
        });

        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('schemy', SchemyVariable::class);
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = ValidatorUtility::class;
        });

        $this->registerGql();
        $this->registerMcp();

        Craft::$app->onInit(function() {
            $request = Craft::$app->getRequest();

            if ($request->getIsCpRequest()) {
                $this->registerCpRoutes();
            } elseif ($request->getIsSiteRequest()) {
                $this->registerAutoInject();
            }
        });
    }

    public function getCpNavItem(): ?array
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }

        $item = $this->customiseNavItem(parent::getCpNavItem(), $this->getSettings());
        $item['subnav'] = [
            'sections' => ['label' => Craft::t('schemy', 'Sections'), 'url' => 'schemy/sections'],
            'identity' => ['label' => Craft::t('schemy', 'Site Identity'), 'url' => 'schemy/identity'],
            'settings' => ['label' => Craft::t('schemy', 'Settings'), 'url' => 'schemy/settings'],
        ];

        return $item;
    }

    /**
     * The sidebar label and icon from settings: pasted SVG (sanitized every time it's drawn), then
     * an icon name, then Schemy's own icon. Craft's Settings → Control Panel Navigation overrides
     * still win, because Craft applies them afterwards. A bad value never breaks the nav.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public function customiseNavItem(array $item, Settings $settings): array
    {
        $label = trim((string)$settings->navLabel);

        if ($label !== '') {
            $item['label'] = $label;
        }

        $svg = trim((string)$settings->navIconSvg);
        $icon = trim((string)$settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitized = Html::svg($svg, true, true, true);

                if ($sanitized !== '') {
                    $item['icon'] = $sanitized;
                    return $item;
                }
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                $item['icon'] = $icon;
            }
        } catch (Throwable $e) {
            Craft::warning("Couldn’t draw the custom nav icon: {$e->getMessage()}", __METHOD__);
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('schemy/settings'));
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['schemy'] = 'schemy/sections/index';
            $event->rules['schemy/sections'] = 'schemy/sections/index';
            $event->rules['schemy/sections/<sectionId:\d+>'] = 'schemy/sections/edit';
            $event->rules['schemy/identity'] = 'schemy/settings/identity';
            $event->rules['schemy/settings'] = 'schemy/settings/general';
        });
    }

    private function registerGql(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $event->queries[Craft::t('schemy', 'Schemy')] = [
                SchemyQuery::COMPONENT . ':read' => [
                    'label' => Craft::t('schemy', 'Query JSON-LD with the `schemy` query'),
                ],
            ];
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, SchemyQuery::getQueries());
        });
    }

    private function registerMcp(): void
    {
        Event::on(Mcp::class, Mcp::EVENT_REGISTER_CAPABILITY_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = SchemyTools::class;
        });
    }

    private function registerAutoInject(): void
    {
        if (!$this->getSettings()->autoInject) {
            return;
        }

        Event::on(View::class, View::EVENT_END_PAGE, function(Event $event) {
            /** @var View $view */
            $view = $event->sender;

            if ($view->getTemplateMode() === View::TEMPLATE_MODE_SITE) {
                $this->schema->injectIntoView($view);
            }
        });
    }
}
