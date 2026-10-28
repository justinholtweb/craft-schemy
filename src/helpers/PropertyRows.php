<?php

namespace justinholtweb\schemy\helpers;

/**
 * Normalizes property-mapping rows coming from the control panel or project config.
 */
class PropertyRows
{
    public const SOURCE_FIELD = 'field';
    public const SOURCE_TEMPLATE = 'template';

    /**
     * Accepts either stored rows (`property`, `source`, `value`) or posted rows
     * (`property`, `source`, `field`, `template`) and returns stored rows.
     * Rows without a property name are dropped.
     */
    public static function normalize(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $property = trim((string)($row['property'] ?? ''));

            // Allow dot paths (address.streetAddress) and @-keywords (address.@type)
            if ($property === '' || !preg_match('/^@?[A-Za-z][A-Za-z0-9]*(\.@?[A-Za-z][A-Za-z0-9]*)*$/', $property)) {
                continue;
            }

            $source = ($row['source'] ?? null) === self::SOURCE_FIELD ? self::SOURCE_FIELD : self::SOURCE_TEMPLATE;

            if (array_key_exists('value', $row)) {
                $value = (string)$row['value'];
            } else {
                $value = (string)($row[$source] ?? '');
            }

            $normalized[] = [
                'property' => $property,
                'source' => $source,
                'value' => $source === self::SOURCE_FIELD ? trim($value) : $value,
            ];
        }

        return $normalized;
    }
}
