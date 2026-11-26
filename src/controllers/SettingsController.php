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
 * General settings (admins, project config) and the site identity node (its own permission,
 * stored in Schemy's table).
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (in_array($action->id, ['identity', 'save-identity'], true)) {
            $this->requirePermission(Plugin::PERMISSION_EDIT_IDENTITY);
        } else {
            // Viewing is fine for any admin; saving needs allowAdminChanges (project config)
            $this->requireAdmin(false);
        }

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
            'identity' => Plugin::getInstance()->store->getIdentity(),
            'identityTypes' => SchemaTypes::IDENTITY_TYPES,
            'typeNames' => SchemaTypes::names(),
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
            'navLabel' => trim((string)$request->getBodyParam('navLabel')) ?: null,
            'navIcon' => trim((string)$request->getBodyParam('navIcon')) ?: null,
            'navIconSvg' => trim((string)$request->getBodyParam('navIconSvg')) ?: null,
        ]);
    }

    public function actionSaveIdentity(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        Plugin::getInstance()->store->saveIdentity(
            (string)$request->getBodyParam('type', ''),
            PropertyRows::normalize($request->getBodyParam('properties', [])),
            SectionsController::vouch(),
        );

        return $this->asSuccess(Craft::t('schemy', 'Site identity saved.'));
    }

    private function save(array $changes): ?Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->schema->saveSettings($changes)) {
            return $this->asModelFailure(
                $plugin->getSettings(),
                Craft::t('schemy', 'Couldn’t save settings.'),
                'settings',
            );
        }

        return $this->asSuccess(Craft::t('schemy', 'Settings saved.'));
    }
}
