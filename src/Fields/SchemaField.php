<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Fields;

use Closure;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Contracts\InputSchemaFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\Ui\Contracts\Control;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Controls\ContentBlock;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field as UiField;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Schemy\Gql\SchemyTypes;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\PropertyTable;
use JustinHoltWeb\Schemy\Models\SchemaOverride;
use JustinHoltWeb\Schemy\Services\Schema;
use Override;

use function CraftCms\Cms\t;

/**
 * Lets authors inherit, customize or disable schema on an individual element.
 */
class SchemaField extends Field implements InputSchemaFieldInterface
{
    /**
     * Whether template values entered on elements are parsed as Twig (in Twig's sandbox).
     * Off by default, since it lets anyone who can edit the element run Twig.
     */
    public bool $allowTwig = false;

    #[Override]
    public static function displayName(): string
    {
        return t('Schema (Schemy)', category: 'schemy');
    }

    #[Override]
    public static function icon(): string
    {
        return 'code';
    }

    #[Override]
    public static function phpType(): string
    {
        return SchemaOverride::class;
    }

    #[Override]
    public static function dbType(): string
    {
        return Query::TYPE_JSON;
    }

    #[Override]
    public function useFieldset(): bool
    {
        return true;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        return Ui::make([
            UiField::make(t('Parse Twig in values', category: 'schemy'), Lightswitch::make('allowTwig')->value($this->allowTwig))
                ->instructions(t('Render template values entered on elements as Twig (e.g. `{title}`), inside Twig’s sandbox. Anyone who can edit an element with this field will be able to run sandboxed Twig, so only enable this when you trust every author.', category: 'schemy')),
        ]);
    }

    #[Override]
    public function normalizeValue(mixed $value, ?ElementInterface $element): SchemaOverride
    {
        if ($value instanceof SchemaOverride) {
            return $value;
        }

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return new SchemaOverride(is_array($value) ? $value : []);
    }

    #[Override]
    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): SchemaOverride
    {
        return $this->normalizeValue($value, $element);
    }

    #[Override]
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        return $value instanceof SchemaOverride ? $value->toArray() : null;
    }

    #[Override]
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        return ! $value instanceof SchemaOverride
            || ($value->mode === SchemaOverride::MODE_INHERIT && $value->extraJson === null);
    }

    /** @return list<Closure> */
    #[Override]
    public function getElementRules(ElementInterface $element): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value instanceof SchemaOverride && $value->extraJson !== null && $value->getExtraNodes() === null) {
                    $fail(t('Additional JSON-LD must be a valid JSON object or array of objects.', category: 'schemy'));
                }
            },
        ];
    }

    #[Override]
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * The editor: mode, type, property rows and extra JSON-LD, built from Craft's own controls.
     */
    #[Override]
    public function uiControl(FieldContext $context): Control
    {
        $value = $this->normalizeValue($context->value, $context->element);
        $schema = app(Schema::class);
        $inherited = $context->element ? $schema->getInheritedConfig($context->element) : null;
        $fieldOptions = $schema->fieldOptions([$context->element?->getFieldLayout()]);

        // Pre-fill the type with what the element would otherwise get, so authors can tweak
        // instead of starting from scratch
        $type = $value->type ?? $inherited['type'] ?? null;

        $summary = $inherited
            ? t('Inherit uses **{type}** from {from}. Customize with the same type layers these properties over the inherited ones; a different type starts fresh.', ['type' => $inherited['type'], 'from' => $inherited['from']], category: 'schemy')
            : t('No schema is set for this element’s section or entry type, so Inherit outputs nothing for it.', category: 'schemy');

        $nodes = [
            Callout::make('schemy-summary', $summary),
            UiField::make(t('Mode', category: 'schemy'), Choice::make('mode')
                ->options([
                    ['label' => t('Inherit', category: 'schemy'), 'value' => SchemaOverride::MODE_INHERIT],
                    ['label' => t('Customize', category: 'schemy'), 'value' => SchemaOverride::MODE_OVERRIDE],
                    ['label' => t('Disable', category: 'schemy'), 'value' => SchemaOverride::MODE_DISABLE],
                ])
                ->presentation(ChoicePresentation::Radios)
                ->value($value->mode))
                ->instructions(t('Disable drops only this element’s node; site-wide nodes stay.', category: 'schemy')),
            UiField::make(t('Type', category: 'schemy'), Combobox::make('type')
                ->options(PropertyTable::typeOptions())
                ->placeholder('Article')
                ->value($type))
                ->instructions(t('Used when the mode is Customize. Any schema.org type works.', category: 'schemy')),
            UiField::make(t('Properties', category: 'schemy'), PropertyTable::make('properties', $value->properties, $fieldOptions, $type, PropertyRows::SOURCE_TEMPLATE))
                ->instructions($this->allowTwig
                    ? t('Used when the mode is Customize. Template values can use Twig, such as `{title}`.', category: 'schemy')
                    : t('Used when the mode is Customize. Template values are used as plain text.', category: 'schemy')),
            UiField::make(t('Additional JSON-LD', category: 'schemy'), Textarea::make('extraJson')
                ->rows(4)
                ->monospace()
                ->placeholder('{"@type": "FAQPage", "mainEntity": [...]}')
                ->value($value->extraJson))
                ->instructions(t('A JSON object or array of objects, added to the page graph as-is, in any mode.', category: 'schemy')),
        ];

        return ContentBlock::make($context->path)
            ->clearable(false)
            ->viewMode('grouped')
            ->ui(Ui::make($nodes))
            // The type shows what the element inherits until the author picks their own
            ->value(['type' => $type] + $value->toArray());
    }

    #[Override]
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (! $value instanceof SchemaOverride) {
            return '';
        }

        return Html::encode(match ($value->mode) {
            SchemaOverride::MODE_OVERRIDE => $value->type ?? t('Customized', category: 'schemy'),
            SchemaOverride::MODE_DISABLE => t('Disabled', category: 'schemy'),
            default => t('Inherited', category: 'schemy'),
        });
    }

    // GraphQL and MCP
    // -------------------------------------------------------------------------

    #[Override]
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => SchemyTypes::fieldData(),
            'description' => $this->instructions,
            'resolve' => function (ElementInterface $source) {
                $value = $source->getFieldValue((string) $this->handle);

                return [
                    'element' => $source,
                    'value' => $value instanceof SchemaOverride ? $value : new SchemaOverride,
                ];
            },
        ];
    }

    #[Override]
    public function getContentGqlMutationArgumentType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => SchemyTypes::fieldInput(),
            'description' => $this->instructions,
        ];
    }

    /**
     * Describes the value for Craft's MCP element tools.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function getInputSchema(): array
    {
        return [
            'type' => ['object', 'null'],
            'description' => 'Schemy schema settings for this entry.',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => SchemaOverride::MODES,
                    'description' => '`inherit` uses the section or entry type schema, `override` uses `type` and `properties` below, `disable` outputs no node for this entry.',
                ],
                'type' => [
                    'type' => ['string', 'null'],
                    'description' => 'A schema.org type such as `Article`. With `override`, the same type as the inherited one layers these properties over the inherited ones; a different type starts fresh.',
                ],
                'properties' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'property' => ['type' => 'string', 'description' => 'A schema.org property. Dot paths (`address.streetAddress`) and `@type` keys are allowed.'],
                            'source' => ['type' => 'string', 'enum' => [PropertyRows::SOURCE_FIELD, PropertyRows::SOURCE_TEMPLATE]],
                            'value' => ['type' => 'string', 'description' => 'A field handle for `field`, or text for `template` (Twig only when the field allows it).'],
                        ],
                        'required' => ['property'],
                        'additionalProperties' => false,
                    ],
                ],
                'extraJson' => [
                    'type' => ['string', 'null'],
                    'description' => 'Extra JSON-LD: a JSON object or array of objects, encoded as a string, added to the page graph.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }
}
