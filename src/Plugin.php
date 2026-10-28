<?php

namespace justinholtweb\schemy;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\schemy\fields\SchemaField;
use justinholtweb\schemy\models\Settings;
use justinholtweb\schemy\services\Schema;
use justinholtweb\schemy\variables\SchemyVariable;
use yii\base\Event;

/**
 * Schemy: schema.org JSON-LD for sections, entry types, and entries.
 *
 * @property-read Schema $schema
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

        $item = parent::getCpNavItem();
        $item['subnav'] = [
            'sections' => ['label' => Craft::t('schemy', 'Sections'), 'url' => 'schemy/sections'],
            'identity' => ['label' => Craft::t('schemy', 'Site Identity'), 'url' => 'schemy/identity'],
            'settings' => ['label' => Craft::t('schemy', 'Settings'), 'url' => 'schemy/settings'],
        ];

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
