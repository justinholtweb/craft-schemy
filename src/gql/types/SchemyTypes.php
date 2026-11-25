<?php

namespace justinholtweb\schemy\gql\types;

use Craft;
use craft\base\ElementInterface;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\models\SchemaOverride;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\services\Schema;

/**
 * GraphQL types for Schemy. Each is created once per request through Craft's type registry.
 */
class SchemyTypes
{
    /**
     * `SchemyDocument`: a rendered JSON-LD document. The source is `['document' => ?array]`.
     */
    public static function document(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SchemyDocument', fn() => new ObjectType([
            'name' => 'SchemyDocument',
            'description' => Craft::t('schemy', 'A JSON-LD document as Schemy would output it.'),
            'fields' => [
                'jsonLd' => [
                    'type' => Type::string(),
                    'description' => Craft::t('schemy', 'The JSON-LD document, encoded as JSON.'),
                    'resolve' => fn(array $source) => $source['document'] === null ? null : Schema::encode($source['document'], false, false),
                ],
                'script' => [
                    'type' => Type::string(),
                    'description' => Craft::t('schemy', 'The full `<script type="application/ld+json">` tag, safe to output as-is.'),
                    'resolve' => fn(array $source) => $source['document'] === null
                        ? null
                        : '<script type="application/ld+json">' . Schema::encode($source['document'], true, false) . '</script>',
                ],
            ],
        ]));
    }

    public static function mode(): EnumType
    {
        return GqlEntityRegistry::getOrCreate('SchemyMode', fn() => new EnumType([
            'name' => 'SchemyMode',
            'values' => [
                SchemaOverride::MODE_INHERIT => ['value' => SchemaOverride::MODE_INHERIT, 'description' => 'Use the section or entry type schema.'],
                SchemaOverride::MODE_OVERRIDE => ['value' => SchemaOverride::MODE_OVERRIDE, 'description' => 'Use this entry’s own type and properties.'],
                SchemaOverride::MODE_DISABLE => ['value' => SchemaOverride::MODE_DISABLE, 'description' => 'Output no node for this entry.'],
            ],
        ]));
    }

    public static function source(): EnumType
    {
        return GqlEntityRegistry::getOrCreate('SchemySource', fn() => new EnumType([
            'name' => 'SchemySource',
            'values' => [
                PropertyRows::SOURCE_FIELD => ['value' => PropertyRows::SOURCE_FIELD, 'description' => 'Read a field or element attribute by handle.'],
                PropertyRows::SOURCE_TEMPLATE => ['value' => PropertyRows::SOURCE_TEMPLATE, 'description' => 'Render a template or use plain text.'],
            ],
        ]));
    }

    public static function propertyRow(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SchemyPropertyRow', fn() => new ObjectType([
            'name' => 'SchemyPropertyRow',
            'fields' => [
                'property' => Type::nonNull(Type::string()),
                'source' => Type::nonNull(self::source()),
                'value' => Type::string(),
            ],
        ]));
    }

    public static function propertyRowInput(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('SchemyPropertyRowInput', fn() => new InputObjectType([
            'name' => 'SchemyPropertyRowInput',
            'fields' => [
                'property' => Type::nonNull(Type::string()),
                'source' => ['type' => self::source(), 'defaultValue' => PropertyRows::SOURCE_TEMPLATE],
                'value' => Type::string(),
            ],
        ]));
    }

    /**
     * `SchemyFieldData`: a Schema field's value. The source is `['element' => ElementInterface, 'value' => SchemaOverride]`.
     */
    public static function fieldData(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('SchemyFieldData', fn() => new ObjectType([
            'name' => 'SchemyFieldData',
            'fields' => [
                'mode' => [
                    'type' => Type::nonNull(self::mode()),
                    'resolve' => fn(array $source) => $source['value']->mode,
                ],
                'type' => [
                    'type' => Type::string(),
                    'description' => 'The entry’s own schema type, when it customizes one.',
                    'resolve' => fn(array $source) => $source['value']->type,
                ],
                'properties' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::propertyRow()))),
                    'resolve' => fn(array $source) => $source['value']->properties,
                ],
                'extraJson' => [
                    'type' => Type::string(),
                    'resolve' => fn(array $source) => $source['value']->extraJson,
                ],
                'jsonLd' => [
                    'type' => Type::string(),
                    'description' => Craft::t('schemy', 'The full JSON-LD document Schemy outputs for this element, encoded as JSON.'),
                    'resolve' => fn(array $source) => self::encodeFor($source['element']),
                ],
            ],
        ]));
    }

    public static function fieldInput(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate('SchemyFieldInput', fn() => new InputObjectType([
            'name' => 'SchemyFieldInput',
            'fields' => [
                'mode' => ['type' => self::mode(), 'defaultValue' => SchemaOverride::MODE_INHERIT],
                'type' => Type::string(),
                'properties' => Type::listOf(Type::nonNull(self::propertyRowInput())),
                'extraJson' => Type::string(),
            ],
        ]));
    }

    private static function encodeFor(ElementInterface $element): ?string
    {
        $document = Plugin::getInstance()->schema->getDocumentForElement($element);
        return $document === null ? null : Schema::encode($document, false, false);
    }
}
