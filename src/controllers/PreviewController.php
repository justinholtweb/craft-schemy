<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\services\Schema;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders an element's JSON-LD for the preview in the Schemy field.
 */
class PreviewController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        $elementsService = Craft::$app->getElements();
        /** @var class-string<ElementInterface>|null $elementType */
        $elementType = $elementsService->getElementTypeById($elementId);

        $element = $elementType ? $elementType::find()
            ->id($elementId)
            ->siteId($siteId)
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->one() : null;

        if ($element === null) {
            throw new NotFoundHttpException('Element not found');
        }

        if (!$elementsService->canView($element)) {
            throw new ForbiddenHttpException('User not authorized to view this element.');
        }

        $plugin = Plugin::getInstance();
        $document = $plugin->schema->getDocumentForElement($element);

        if ($document === null) {
            return $this->asJson(['json' => '', 'validation' => null]);
        }

        return $this->asJson([
            'json' => Schema::encode($document),
            'validation' => $plugin->validator->validateDocument($document)->toArray(),
        ]);
    }
}
