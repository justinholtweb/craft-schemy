<?php

namespace justinholtweb\schemy\web\assets\cp;

use craft\helpers\Json;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;
use craft\web\View;
use justinholtweb\schemy\helpers\SchemaTypes;

class CpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CraftCpAsset::class];
        $this->js = ['schemy.js'];
        $this->css = ['schemy.css'];

        parent::init();
    }

    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerJs(
                'window.SchemyTypes = ' . Json::encode(SchemaTypes::catalog()) . ';',
                View::POS_HEAD,
                'schemy-types',
            );
            $view->registerTranslations('schemy', [
                'Preview unavailable.',
                'Loading…',
                'Error',
                'Warning',
                'Notice',
                'No issues found.',
                '{errors, plural, =1{# error} other{# errors}}, {warnings, plural, =1{# warning} other{# warnings}}, {notices, plural, =1{# notice} other{# notices}}',
                'Select an entry or paste some JSON-LD.',
                'Validation failed.',
            ]);
        }
    }
}
