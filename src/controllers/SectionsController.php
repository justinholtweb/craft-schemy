<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\models\Section;
use craft\web\Controller;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\web\assets\cp\CpAsset;
use yii\web\ForbiddenHttpException;
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

        // Section schema lives in Schemy's table, not project config, so it can be edited in any
        // environment by anyone with the permission
        if ($action->id === 'index' && !$this->currentUser()?->can(Plugin::PERMISSION_EDIT_SECTIONS)
            && $this->currentUser()?->can(Plugin::PERMISSION_EDIT_IDENTITY)
        ) {
            // Landing on Schemy without access to Sections
            $this->redirect('schemy/identity')->send();
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_EDIT_SECTIONS);

        return true;
    }

    public function actionIndex(): Response
    {
        $configs = Plugin::getInstance()->store->getAllSectionConfigs();
        $groups = [
            Section::TYPE_SINGLE => ['label' => Craft::t('app', 'Singles'), 'sections' => []],
            Section::TYPE_CHANNEL => ['label' => Craft::t('app', 'Channels'), 'sections' => []],
            Section::TYPE_STRUCTURE => ['label' => Craft::t('app', 'Structures'), 'sections' => []],
        ];

        $user = static::currentUser();

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if (!Plugin::canManageSection($user, $section)) {
                continue;
            }

            $config = $configs[$section->uid] ?? null;

            $groups[$section->type]['sections'][] = [
                'section' => $section,
                'type' => $config['type'] ?? null,
                'overrideCount' => count($config['entryTypes'] ?? []),
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

        if (!Plugin::canManageSection(static::currentUser(), $section)) {
            throw new ForbiddenHttpException('User not authorized to view this section’s entries.');
        }

        $schema = Plugin::getInstance()->schema;
        $config = $schema->getSectionConfig($section);
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
            'untrusted' => $config['untrusted'],
            'type' => $config['type'] ?? null,
            'properties' => PropertyRows::normalize($config['properties'] ?? []),
            'fieldOptions' => $schema->fieldOptions(array_map(fn($et) => $et->getFieldLayout(), $entryTypes)),
            'entryTypeConfigs' => $entryTypeConfigs,
            'typeNames' => SchemaTypes::names(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $section = Craft::$app->getEntries()->getSectionById((int)$request->getRequiredBodyParam('sectionId'));

        if ($section === null) {
            throw new NotFoundHttpException('Section not found');
        }

        if (!Plugin::canManageSection(static::currentUser(), $section)) {
            throw new ForbiddenHttpException('User not authorized to view this section’s entries.');
        }

        $entryTypes = [];
        foreach ($section->getEntryTypes() as $entryType) {
            $entryTypes[$entryType->uid] = $request->getBodyParam("entryTypes.{$entryType->uid}", []);
        }

        $saved = Plugin::getInstance()->schema->saveSectionConfig($section, [
            'type' => $request->getBodyParam('type', ''),
            'properties' => $request->getBodyParam('properties', []),
            'entryTypes' => $entryTypes,
        ], self::vouch());

        if (!$saved) {
            return $this->asFailure(Craft::t('schemy', 'Couldn’t save schema settings.'));
        }

        return $this->asSuccess(Craft::t('schemy', 'Schema saved for {section}.', ['section' => $section->name]));
    }

    /**
     * True when an admin ticked "Run these values as Twig" on a page holding values saved by
     * someone who isn't an admin. Null leaves it to the signed-in user.
     */
    public static function vouch(): ?bool
    {
        return Craft::$app->getUser()->getIsAdmin() && Craft::$app->getRequest()->getBodyParam('trustTwig') ? true : null;
    }
}
