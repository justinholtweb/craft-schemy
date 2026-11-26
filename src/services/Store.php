<?php

namespace justinholtweb\schemy\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;

/**
 * Reads and writes section, entry type and site identity schema, which live in Schemy's own
 * table (not project config) so they can be edited in any environment.
 *
 * Each row records whether it holds template values from someone other than an admin
 * (`untrusted`). Twig in those rows isn't run (see `Schema::templateValue()`). The flag sticks:
 * only an admin explicitly vouching for the values (`$savedBy === true`) clears it, or saving
 * values with no Twig in them.
 */
class Store extends Component
{
    public const TABLE = '{{%schemy_schemas}}';

    /** The scope of the site identity row. Section rows use the section UID. */
    public const IDENTITY = 'identity';

    public const DEFAULT_IDENTITY_TYPE = 'Organization';

    public const DEFAULT_IDENTITY = [
        ['property' => 'name', 'source' => 'template', 'value' => '{{ siteName }}'],
        ['property' => 'url', 'source' => 'template', 'value' => '{{ siteUrl }}'],
    ];

    /** @var array<string, array>|null Rows keyed by scope, loaded once per request */
    private ?array $rows = null;

    /**
     * Every section's config, keyed by section UID.
     *
     * @return array<string, array{type: string, properties: array, entryTypes: array<string, array{override: bool, type: string, properties: array}>, untrusted: bool}>
     */
    public function getAllSectionConfigs(): array
    {
        $configs = [];

        foreach ($this->rows() as $scope => $row) {
            if ($scope !== self::IDENTITY) {
                $configs[$scope] = $this->sectionConfigFromRow($row);
            }
        }

        return $configs;
    }

    /**
     * A section's config in a predictable shape. Sections without one get an empty config.
     *
     * @return array{type: string, properties: array, entryTypes: array<string, array{override: bool, type: string, properties: array}>, untrusted: bool}
     */
    public function getSectionConfig(string $sectionUid): array
    {
        return $this->sectionConfigFromRow($this->rows()[$sectionUid] ?? []);
    }

    /**
     * Replaces a section's config. An empty config deletes the row.
     *
     * @param array{type?: string, properties?: array, entryTypes?: array<string, array>} $config Already cleaned
     * @param User|bool|null $savedBy Who vouches for the Twig: a user (an MCP actor), or true/false
     * from code that knows. Null uses the signed-in user.
     */
    public function saveSectionConfig(string $sectionUid, array $config, User|bool|null $savedBy = null): bool
    {
        $type = (string)($config['type'] ?? '');
        $entryTypes = [];

        foreach ($config['entryTypes'] ?? [] as $uid => $entryTypeConfig) {
            $entryTypes[$uid] = [
                'type' => (string)($entryTypeConfig['type'] ?? ''),
                'properties' => PropertyRows::normalize($entryTypeConfig['properties'] ?? []),
            ];
        }

        if ($type === '' && $entryTypes === []) {
            Db::delete(self::TABLE, ['scope' => $sectionUid]);
        } else {
            $this->upsert($sectionUid, [
                'type' => $type,
                'properties' => PropertyRows::normalize($config['properties'] ?? []),
                'entryTypes' => $entryTypes,
            ], $savedBy);
        }

        $this->rows = null;

        return true;
    }

    /**
     * The site identity node's type and property rows.
     *
     * @return array{type: string, properties: array, untrusted: bool}
     */
    public function getIdentity(): array
    {
        $row = $this->rows()[self::IDENTITY] ?? null;

        if ($row === null) {
            return [
                'type' => self::DEFAULT_IDENTITY_TYPE,
                'properties' => self::DEFAULT_IDENTITY,
                'untrusted' => false,
            ];
        }

        return [
            'type' => (string)$row['type'] ?: self::DEFAULT_IDENTITY_TYPE,
            'properties' => PropertyRows::normalize(self::decode($row['properties'])),
            'untrusted' => (bool)$row['untrusted'],
        ];
    }

    /**
     * @param User|bool|null $savedBy Who vouches for the Twig: a user (an MCP actor), or true/false
     * from code that knows. Null uses the signed-in user.
     */
    public function saveIdentity(string $type, array $properties, User|bool|null $savedBy = null): bool
    {
        $type = SchemaTypes::cleanName($type) ?: self::DEFAULT_IDENTITY_TYPE;

        $this->upsert(self::IDENTITY, [
            'type' => $type,
            'properties' => PropertyRows::normalize($properties),
            'entryTypes' => null,
        ], $savedBy);

        $this->rows = null;

        return true;
    }

    /**
     * Whether Twig saved now can be run. Only an admin, or code that passes `true` because it
     * knows (an admin vouching on the page, an import of trusted rows), is trusted. Nobody signed
     * in, on the console or the web, is not.
     */
    public static function isTrusted(User|bool|null $savedBy = null): bool
    {
        if (is_bool($savedBy)) {
            return $savedBy;
        }

        $savedBy ??= Craft::$app->getUser()->getIdentity();

        return $savedBy instanceof User && $savedBy->admin;
    }

    private function upsert(string $scope, array $columns, User|bool|null $savedBy): void
    {
        $previous = $this->rows()[$scope] ?? null;

        $hasTwig = self::hasTwig($columns);

        if ($savedBy === true) {
            // An explicit vouch: an admin ticking "Run these values as Twig", or a trusted import
            $untrusted = false;
        } elseif (!self::isTrusted($savedBy)) {
            $untrusted = $hasTwig;
        } else {
            // An admin saving the page doesn't vouch for values they didn't write: it stays
            // untrusted until they say so, or until no value could be Twig
            $untrusted = $previous !== null && $previous['untrusted'] && $hasTwig;
        }

        $columns['untrusted'] = $untrusted;
        Db::upsert(self::TABLE, ['scope' => $scope] + $columns, $columns);
    }

    /**
     * Whether any value in the row could be Twig, whatever its source (a field handle could
     * become a template once its source changes): its own properties and its entry types'.
     */
    private static function hasTwig(array $row): bool
    {
        $rows = PropertyRows::normalize(self::decode($row['properties'] ?? null));

        foreach (self::decode($row['entryTypes'] ?? null) as $entryTypeConfig) {
            if (is_array($entryTypeConfig)) {
                array_push($rows, ...PropertyRows::normalize($entryTypeConfig['properties'] ?? []));
            }
        }

        foreach ($rows as $propertyRow) {
            if (str_contains($propertyRow['value'], '{')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (!Craft::$app->getDb()->tableExists(self::TABLE)) {
            // Updated to 5.2 but the migration hasn't run yet: keep serving the old config
            return $this->rows = self::legacyRows();
        }

        return $this->rows = (new Query())
            ->select(['scope', 'type', 'properties', 'entryTypes', 'untrusted'])
            ->from(self::TABLE)
            ->indexBy('scope')
            ->all();
    }

    /**
     * Rows built from the project config keys used before 5.2.
     *
     * @return array<string, array>
     */
    private static function legacyRows(): array
    {
        $settings = ProjectConfigHelper::unpackAssociativeArrays(Craft::$app->getProjectConfig()->get('plugins.schemy.settings') ?? []);
        $rows = [];

        foreach ((array)($settings['sections'] ?? []) as $uid => $config) {
            if (is_string($uid) && is_array($config)) {
                $rows[$uid] = [
                    'type' => $config['type'] ?? '',
                    'properties' => $config['properties'] ?? [],
                    'entryTypes' => array_filter((array)($config['entryTypes'] ?? []), fn($c) => is_array($c) && !empty($c['override'])),
                    'untrusted' => false,
                ];
            }
        }

        if (isset($settings['identity'])) {
            $rows[self::IDENTITY] = [
                'type' => $settings['identityType'] ?? '',
                'properties' => $settings['identity'],
                'entryTypes' => null,
                'untrusted' => false,
            ];
        }

        return $rows;
    }

    private function sectionConfigFromRow(array $row): array
    {
        $entryTypes = [];

        foreach (self::decode($row['entryTypes'] ?? null) as $uid => $entryTypeConfig) {
            if (is_string($uid) && is_array($entryTypeConfig)) {
                $entryTypes[$uid] = [
                    'override' => true,
                    'type' => (string)($entryTypeConfig['type'] ?? ''),
                    'properties' => PropertyRows::normalize($entryTypeConfig['properties'] ?? []),
                ];
            }
        }

        return [
            'type' => (string)($row['type'] ?? ''),
            'properties' => PropertyRows::normalize(self::decode($row['properties'] ?? null)),
            'entryTypes' => $entryTypes,
            'untrusted' => (bool)($row['untrusted'] ?? false),
        ];
    }

    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && $value !== '' ? Json::decodeIfJson($value) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
