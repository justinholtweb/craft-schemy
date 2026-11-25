<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Helpers;

use CraftCms\Cms\Ui\Controls\Combobox;
use CraftCms\Cms\Ui\Controls\Table;

use function CraftCms\Cms\t;

/**
 * The editable table of property rows used by the Schema field and the control panel screens:
 * a property name, a source (field or template) and a value.
 */
final class PropertyTable
{
    /**
     * @param  string|list<string>  $path
     * @param  list<array{label: string, value: string}>  $fieldOptions  Fields and attributes offered as values. Empty hides the source column.
     * @param  list<array{property: string, source: string, value: string}>  $rows
     * @param  string  $defaultSource  The source new rows start with when fields are offered
     */
    public static function make(string|array $path, array $rows, array $fieldOptions = [], ?string $type = null, string $defaultSource = PropertyRows::SOURCE_FIELD): Table
    {
        $columns = [
            'property' => [
                'heading' => t('Property', category: 'schemy'),
                'control' => Combobox::make('property')
                    ->options(self::propertyOptions($type))
                    ->placeholder('headline')
                    ->showAllOnEmpty(),
            ],
        ];

        if ($fieldOptions !== []) {
            $columns['source'] = [
                'heading' => t('Source', category: 'schemy'),
                'type' => 'select',
                'options' => [
                    ['label' => t('Field', category: 'schemy'), 'value' => PropertyRows::SOURCE_FIELD],
                    ['label' => t('Template', category: 'schemy'), 'value' => PropertyRows::SOURCE_TEMPLATE],
                ],
            ];
        } else {
            $columns['source'] = ['type' => 'hidden'];
        }

        // A field handle for the Field source, a template or plain text for the Template source.
        // One column serves both, suggesting the fields in play.
        $columns['value'] = [
            'heading' => t('Value', category: 'schemy'),
            'control' => Combobox::make('value')
                ->options($fieldOptions)
                ->placeholder('{title}'),
        ];

        return Table::make($path)
            ->columns($columns)
            ->allowAdd()
            ->allowDelete()
            ->allowReorder()
            ->addRowLabel(t('Add a property', category: 'schemy'))
            ->defaultValues(['source' => $fieldOptions !== [] ? $defaultSource : PropertyRows::SOURCE_TEMPLATE])
            ->value(array_map(fn (array $row) => [
                'property' => $row['property'],
                'source' => $row['source'],
                'value' => $row['value'],
            ], $rows));
    }

    /**
     * Property names to suggest: the type's own when it's known, otherwise every property in the catalog.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function propertyOptions(?string $type): array
    {
        $names = $type !== null && SchemaTypes::exists($type)
            ? array_keys(SchemaTypes::properties($type))
            : array_values(array_unique(array_merge(...array_map(
                fn (string $name) => array_keys(SchemaTypes::properties($name)),
                SchemaTypes::names(),
            ))));

        sort($names);

        return array_map(fn (string $name) => ['label' => $name, 'value' => $name], $names);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public static function typeOptions(?array $names = null): array
    {
        return array_map(fn (string $name) => ['label' => $name, 'value' => $name], $names ?? SchemaTypes::names());
    }
}
