<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\web\assets\cp\CpAsset;
use yii\web\Response;

/**
 * General settings and the site identity node.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    public function actionGeneral(): Response
    {
        return $this->renderTemplate('schemy/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionIdentity(): Response
    {
        $this->getView()->registerAssetBundle(CpAsset::class);

        return $this->renderTemplate('schemy/identity', [
            'settings' => Plugin::getInstance()->getSettings(),
            'identityTypes' => SchemaTypes::IDENTITY_TYPES,
            'typeNames' => SchemaTypes::names(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSaveGeneral(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();

        return $this->save([
            'autoInject' => (bool)$request->getBodyParam('autoInject'),
            'includeWebSite' => (bool)$request->getBodyParam('includeWebSite'),
            'includeIdentity' => (bool)$request->getBodyParam('includeIdentity'),
            'includeBreadcrumbs' => (bool)$request->getBodyParam('includeBreadcrumbs'),
        ]);
    }

    public function actionSaveIdentity(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();

        return $this->save([
            'identityType' => trim((string)$request->getBodyParam('type', 'Organization')) ?: 'Organization',
            'identity' => PropertyRows::normalize($request->getBodyParam('properties', [])),
        ]);
    }

    private function save(array $changes): ?Response
    {
        $plugin = Plugin::getInstance();

        // savePluginSettings() only writes the keys it's given, so always pass everything
        $settings = array_merge($plugin->getSettings()->toArray(), $changes);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->asModelFailure(
                $plugin->getSettings(),
                Craft::t('schemy', 'Couldn’t save settings.'),
                'settings',
            );
        }

        return $this->asSuccess(Craft::t('schemy', 'Settings saved.'));
    }
}
