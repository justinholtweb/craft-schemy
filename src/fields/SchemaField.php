<?php

namespace justinholtweb\schemy\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Json;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\models\SchemaOverride;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\web\assets\cp\CpAsset;
use yii\db\Schema as DbSchema;

/**
 * Lets authors inherit, override, or disable schema on an individual element.
 */
class SchemaField extends Field
{
    /**
     * Whether template values entered on elements are parsed as Twig.
     * Off by default, since it lets anyone who can edit the element run Twig.
     */
    public bool $allowTwig = false;

    public static function displayName(): string
    {
        return Craft::t('schemy', 'Schema (Schemy)');
    }

    public static function icon(): string
    {
        return 'code';
    }

    public static function phpType(): string
    {
        return SchemaOverride::class;
    }

    public static function dbType(): array|string|null
    {
        return DbSchema::TYPE_JSON;
    }

    public function useFieldset(): bool
    {
        return true;
    }

    public function getSettingsHtml(): ?string
    {
        return Cp::lightswitchFieldHtml([
            'label' => Craft::t('schemy', 'Parse Twig in values'),
            'instructions' => Craft::t('schemy', 'Render template values entered on elements as Twig (e.g. `{title}`). Anyone who can edit an element with this field will be able to run Twig, so only enable this when you trust every author.'),
            'id' => 'allowTwig',
            'name' => 'allowTwig',
            'on' => $this->allowTwig,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof SchemaOverride) {
            return $value;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return new SchemaOverride(is_array($value) ? $value : []);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!$value instanceof SchemaOverride) {
            return null;
        }

        return [
            'mode' => $value->mode,
            'type' => $value->type,
            'properties' => $value->properties,
            'extraJson' => $value->extraJson,
        ];
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return !$value instanceof SchemaOverride
            || ($value->mode === SchemaOverride::MODE_INHERIT && $value->extraJson === null);
    }

    public function getElementValidationRules(): array
    {
        return ['validateExtraJson'];
    }

    public function validateExtraJson(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        if ($value instanceof SchemaOverride && $value->extraJson !== null && $value->getExtraNodes() === null) {
            $element->addError(
                "field:$this->handle",
                Craft::t('schemy', 'Additional JSON-LD must be a valid JSON object or array of objects.'),
            );
        }
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        /** @var SchemaOverride $value */
        $view = Craft::$app->getView();
        $view->registerAssetBundle(CpAsset::class);

        $schema = Plugin::getInstance()->schema;
        $inherited = $element ? $schema->getInheritedConfig($element) : null;
        $id = $this->getInputId();

        // Pre-fill the editor with what the element would otherwise get, so authors
        // can tweak instead of starting from scratch
        $editorType = $value->type ?? $inherited['type'] ?? null;
        $editorRows = $value->mode === SchemaOverride::MODE_OVERRIDE ? $value->properties : [];

        $html = $view->renderTemplate('schemy/field/input', [
            'id' => $id,
            'name' => $this->handle,
            'value' => $value,
            'inherited' => $inherited,
            'editorType' => $editorType,
            'editorRows' => $editorRows,
            'fieldOptions' => $schema->fieldOptions([$element?->getFieldLayout()]),
            'allowTwig' => $this->allowTwig,
            'element' => $element,
            'typeNames' => SchemaTypes::names(),
        ]);

        $view->registerJs(sprintf('Schemy.initField(%s);', Json::encode($view->namespaceInputId($id))));

        return $html;
    }
}
