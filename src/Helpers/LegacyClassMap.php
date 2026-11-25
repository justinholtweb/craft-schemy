<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Helpers;

use JustinHoltWeb\Schemy\Fields\SchemaField;

/**
 * Schemy 5's class names that Craft stores, mapped to Schemy 6's.
 *
 * Schemy 5 stored only its field type (`fields.type` and `fields.<uid>.type` in project config).
 * A Schemy 5 class name is a whole stored value, so values are replaced only on an exact match.
 */
final class LegacyClassMap
{
    public const array MAP = [
        'justinholtweb\\schemy\\fields\\SchemaField' => SchemaField::class,
    ];

    public static function className(string $value): string
    {
        return self::MAP[$value] ?? $value;
    }

    /**
     * Replaces Schemy 5 class names anywhere in a stored value: plain strings, array keys and
     * nested arrays (project config, including its `__assoc__` pairs).
     */
    public static function replace(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::MAP[$value] ?? $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $updated = [];

        foreach ($value as $key => $item) {
            $updated[is_string($key) ? self::className($key) : $key] = self::replace($item);
        }

        return $updated;
    }
}
