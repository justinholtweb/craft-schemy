<?php

namespace justinholtweb\schemy\models;

use craft\base\Model;
use justinholtweb\schemy\helpers\PropertyRows;

/**
 * The value of a Schemy field on an individual element.
 */
class SchemaOverride extends Model
{
    public const MODE_INHERIT = 'inherit';
    public const MODE_OVERRIDE = 'override';
    public const MODE_DISABLE = 'disable';

    public string $mode = self::MODE_INHERIT;
    public ?string $type = null;
    public array $properties = [];

    /** Raw JSON-LD (an object or array of objects) appended to the page graph. */
    public ?string $extraJson = null;

    public function __construct($config = [])
    {
        if (isset($config['properties']) && is_array($config['properties'])) {
            $config['properties'] = PropertyRows::normalize($config['properties']);
        }

        if (!in_array($config['mode'] ?? null, [self::MODE_INHERIT, self::MODE_OVERRIDE, self::MODE_DISABLE], true)) {
            unset($config['mode']);
        }

        foreach (['type', 'extraJson'] as $key) {
            if (isset($config[$key])) {
                $config[$key] = trim((string)$config[$key]) ?: null;
            }
        }

        // Ignore anything we don't know about (e.g. stale keys from older versions)
        $config = array_intersect_key($config, array_flip(['mode', 'type', 'properties', 'extraJson']));

        parent::__construct($config);
    }

    /**
     * Returns the decoded extra JSON-LD as a list of nodes, or null if it's empty or invalid.
     */
    public function getExtraNodes(): ?array
    {
        if ($this->extraJson === null) {
            return null;
        }

        $decoded = json_decode($this->extraJson, true);

        if (!is_array($decoded)) {
            return null;
        }

        return array_is_list($decoded) ? array_values(array_filter($decoded, 'is_array')) : [$decoded];
    }
}
