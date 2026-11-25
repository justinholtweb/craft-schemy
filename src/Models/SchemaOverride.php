<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Models;

use JustinHoltWeb\Schemy\Helpers\PropertyRows;

/**
 * The value of a Schema field on an individual element.
 */
final class SchemaOverride
{
    public const string MODE_INHERIT = 'inherit';

    public const string MODE_OVERRIDE = 'override';

    public const string MODE_DISABLE = 'disable';

    public const array MODES = [self::MODE_INHERIT, self::MODE_OVERRIDE, self::MODE_DISABLE];

    public string $mode = self::MODE_INHERIT;

    public ?string $type = null;

    /** @var list<array{property: string, source: string, value: string}> */
    public array $properties = [];

    /** Raw JSON-LD (an object or array of objects) appended to the page graph. */
    public ?string $extraJson = null;

    /**
     * @param  array<string, mixed>  $config  Stored or posted data. Unknown keys (e.g. stale ones from older versions) are ignored.
     */
    public function __construct(array $config = [])
    {
        if (in_array($config['mode'] ?? null, self::MODES, true)) {
            $this->mode = $config['mode'];
        }

        if (is_array($config['properties'] ?? null)) {
            $this->properties = PropertyRows::normalize($config['properties']);
        }

        foreach (['type', 'extraJson'] as $key) {
            if (isset($config[$key]) && is_scalar($config[$key])) {
                $this->$key = trim((string) $config[$key]) ?: null;
            }
        }
    }

    /**
     * Returns the decoded extra JSON-LD as a list of nodes, or null if it's empty or invalid.
     *
     * @return list<array<mixed>>|null
     */
    public function getExtraNodes(): ?array
    {
        if ($this->extraJson === null) {
            return null;
        }

        $decoded = json_decode($this->extraJson, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_is_list($decoded) ? array_values(array_filter($decoded, 'is_array')) : [$decoded];
    }

    /**
     * @return array{mode: string, type: ?string, properties: list<array{property: string, source: string, value: string}>, extraJson: ?string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'type' => $this->type,
            'properties' => $this->properties,
            'extraJson' => $this->extraJson,
        ];
    }
}
