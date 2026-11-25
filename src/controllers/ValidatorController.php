<?php

namespace justinholtweb\schemy\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\services\Schema;
use justinholtweb\schemy\utilities\ValidatorUtility;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Backs the Schema Validator utility.
 */
class ValidatorController extends Controller
{
    public function actionValidate(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireCpRequest();
        $this->requirePermission('utility:' . ValidatorUtility::id());

        $request = Craft::$app->getRequest();
        $entryId = (int)$request->getBodyParam('entryId');
        $validator = Plugin::getInstance()->validator;

        if ($entryId) {
            $entry = Entry::find()
                ->id($entryId)
                ->siteId((int)$request->getBodyParam('siteId') ?: null)
                ->status(null)
                ->one();

            if ($entry === null) {
                throw new NotFoundHttpException('Entry not found');
            }

            if (!Craft::$app->getElements()->canView($entry)) {
                throw new ForbiddenHttpException('User not authorized to view this entry.');
            }

            $result = $validator->validateElement($entry);
        } else {
            $result = $validator->validateJson(self::stripScriptTag((string)$request->getBodyParam('json', '')));
        }

        return $this->asJson($result->toArray() + [
            'json' => $result->document === null ? '' : Schema::encode($result->document),
        ]);
    }

    /**
     * Accepts JSON wrapped in a `<script type="application/ld+json">` tag, as copied from page source.
     */
    public static function stripScriptTag(string $input): string
    {
        $input = trim($input);

        if (preg_match('#^<script\b[^>]*>(.*)</script>$#is', $input, $match)) {
            return trim($match[1]);
        }

        return $input;
    }
}
