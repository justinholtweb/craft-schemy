<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\models\Section;
use craft\web\Controller;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\web\assets\cp\CpAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Section and entry type schema defaults.
 */
class SectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Viewing is fine for any admin; saving needs allowAdminChanges (project config)
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        $configs = Plugin::getInstance()->getSettings()->sections;
        $groups = [
            Section::TYPE_SINGLE => ['label' => Craft::t('app', 'Singles'), 'sections' => []],
            Section::TYPE_CHANNEL => ['label' => Craft::t('app', 'Channels'), 'sections' => []],
            Section::TYPE_STRUCTURE => ['label' => Craft::t('app', 'Structures'), 'sections' => []],
        ];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $config = $configs[$section->uid] ?? [];
            $overrides = array_filter($config['entryTypes'] ?? [], fn($c) => !empty($c['override']));

            $groups[$section->type]['sections'][] = [
                'section' => $section,
                'type' => $config['type'] ?? null,
                'overrideCount' => count($overrides),
            ];
        }

        return $this->renderTemplate('schemy/sections/index', [
            'groups' => array_filter($groups, fn($g) => !empty($g['sections'])),
        ]);
    }

    public function actionEdit(int $sectionId): Response
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);

        if ($section === null) {
            throw new NotFoundHttpException('Section not found');
        }

        $schema = Plugin::getInstance()->schema;
        $config = Plugin::getInstance()->getSettings()->sections[$section->uid] ?? [];
        $entryTypes = $section->getEntryTypes();

        $entryTypeConfigs = [];
        foreach ($entryTypes as $entryType) {
            $c = $config['entryTypes'][$entryType->uid] ?? [];
            $entryTypeConfigs[] = [
                'entryType' => $entryType,
                'override' => !empty($c['override']),
                'type' => $c['type'] ?? null,
                'properties' => PropertyRows::normalize($c['properties'] ?? []),
                'fieldOptions' => $schema->fieldOptions([$entryType->getFieldLayout()]),
            ];
        }

        $this->getView()->registerAssetBundle(CpAsset::class);

        return $this->renderTemplate('schemy/sections/_edit', [
            'section' => $section,
            'type' => $config['type'] ?? null,
            'properties' => PropertyRows::normalize($config['properties'] ?? []),
            'fieldOptions' => $schema->fieldOptions(array_map(fn($et) => $et->getFieldLayout(), $entryTypes)),
            'entryTypeConfigs' => $entryTypeConfigs,
            'typeNames' => SchemaTypes::names(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $request = Craft::$app->getRequest();
        $section = Craft::$app->getEntries()->getSectionById((int)$request->getRequiredBodyParam('sectionId'));

        if ($section === null) {
            throw new NotFoundHttpException('Section not found');
        }

        $entryTypes = [];
        foreach ($section->getEntryTypes() as $entryType) {
            $posted = $request->getBodyParam("entryTypes.{$entryType->uid}", []);
            if (empty($posted['override'])) {
                continue;
            }
            $entryTypes[$entryType->uid] = [
                'override' => true,
                'type' => $this->cleanType($posted['type'] ?? ''),
                'properties' => PropertyRows::normalize($posted['properties'] ?? []),
            ];
        }

        $config = [
            'type' => $this->cleanType($request->getBodyParam('type', '')),
            'properties' => PropertyRows::normalize($request->getBodyParam('properties', [])),
            'entryTypes' => $entryTypes,
        ];

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings()->toArray();

        if ($config['type'] === '' && empty($entryTypes)) {
            unset($settings['sections'][$section->uid]);
        } else {
            $settings['sections'][$section->uid] = $config;
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            return $this->asFailure(Craft::t('schemy', 'Couldn’t save schema settings.'));
        }

        return $this->asSuccess(Craft::t('schemy', 'Schema saved for {section}.', ['section' => $section->name]));
    }

    private function cleanType(mixed $type): string
    {
        $type = trim((string)$type);
        return preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $type) ? $type : '';
    }
}
