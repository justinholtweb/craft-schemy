<?php

namespace justinholtweb\schemy\utilities;

use Craft;
use craft\base\Utility;
use justinholtweb\schemy\web\assets\cp\CpAsset;

/**
 * Utilities → Schema Validator: checks an entry's JSON-LD or pasted markup.
 */
class ValidatorUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('schemy', 'Schema Validator');
    }

    public static function id(): string
    {
        return 'schemy-validator';
    }

    public static function icon(): ?string
    {
        return 'list-check';
    }

    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        return $view->renderTemplate('schemy/utilities/validator', [
            'id' => 'schemy-validator',
        ]);
    }
}
