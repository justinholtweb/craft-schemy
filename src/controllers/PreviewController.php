<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\web\Controller;
use justinholtweb\schemy\Plugin;
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

        // Render object templates as the front end would
        $sites = Craft::$app->getSites();
        $originalSite = $sites->getCurrentSite();
        $sites->setCurrentSite($element->getSite());

        try {
            $document = Plugin::getInstance()->schema->getDocument($element);
        } finally {
            $sites->setCurrentSite($originalSite);
        }

        return $this->asJson([
            'json' => $document === null ? '' : json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }
}
