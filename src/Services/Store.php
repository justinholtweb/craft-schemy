<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Services;

use CraftCms\Cms\ProjectConfig\ProjectConfig;
use CraftCms\Cms\ProjectConfig\ProjectConfigHelper;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Str;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;

use function CraftCms\Cms\currentUser;

/**
 * Reads and writes section, entry type and site identity schema, which live in Schemy's own
 * table (not project config) so they can be edited in any environment.
 *
 * Each row records whether it holds values from someone other than an admin (`untrusted`). Twig
 * in those rows isn't run. The flag sticks: only an admin explicitly vouching (`$savedBy === true`)
 * or saving values with no Twig in them clears it, and export/import carries it.
 *
 * Scoped: rows are read once per request.
 */
#[Scoped]
class Store
{
    public const string TABLE = 'schemy_schemas';

    /** The scope of the site identity row. Section rows use the section UID. */
    public const string IDENTITY = 'identity';

    public const string DEFAULT_IDENTITY_TYPE = 'Organization';

    public const array DEFAULT_IDENTITY = [
        ['property' => 'name', 'source' => 'template', 'value' => '{{ siteName }}'],
        ['property' => 'url', 'source' => 'template', 'value' => '{{ siteUrl }}'],
    ];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    /**
     * Every section's config, keyed by section UID.
     *
     * @return array<string, array{type: string, properties: list<array{property: string, source: string, value: string}>, entryTypes: array<string, array{override: true, type: string, properties: list<array{property: string, source: string, value: string}>}>, untrusted: bool}>
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
     * @return array{type: string, properties: list<array{property: string, source: string, value: string}>, entryTypes: array<string, array{override: true, type: string, properties: list<array{property: string, source: string, value: string}>}>, untrusted: bool}
     */
    public function getSectionConfig(string $sectionUid): array
    {
        return $this->sectionConfigFromRow($this->rows()[$sectionUid] ?? []);
    }

    /**
     * Replaces a section's config. An empty config deletes the row.
     *
     * @param  array{type?: string, properties?: array<mixed>, entryTypes?: array<string, array<mixed>>}  $config  Already cleaned
     * @param  CraftUser|bool|null  $savedBy  Who vouches for the Twig: a user (an MCP actor), or true/false from code that knows. Null uses the signed-in user.
     */
    public function saveSectionConfig(string $sectionUid, array $config, CraftUser|bool|null $savedBy = null): bool
    {
        $type = (string) ($config['type'] ?? '');
        $entryTypes = [];

        foreach ($config['entryTypes'] ?? [] as $uid => $entryTypeConfig) {
            $entryTypes[$uid] = [
                'type' => (string) ($entryTypeConfig['type'] ?? ''),
                'properties' => PropertyRows::normalize((array) ($entryTypeConfig['properties'] ?? [])),
            ];
        }

        if ($type === '' && $entryTypes === []) {
            DB::table(self::TABLE)->where('scope', $sectionUid)->delete();
        } else {
            $this->upsert($sectionUid, [
                'type' => $type,
                'properties' => PropertyRows::normalize((array) ($config['properties'] ?? [])),
                'entryTypes' => $entryTypes,
            ], $savedBy);
        }

        $this->rows = null;

        return true;
    }

    /**
     * @return array{type: string, properties: list<array{property: string, source: string, value: string}>, untrusted: bool}
     */
    public function getIdentity(): array
    {
        $row = $this->rows()[self::IDENTITY] ?? null;

        if ($row === null) {
            return [
                'type' => self::DEFAULT_IDENTITY_TYPE,
                'properties' => PropertyRows::normalize(self::DEFAULT_IDENTITY),
                'untrusted' => false,
            ];
        }

        return [
            'type' => (string) $row['type'] ?: self::DEFAULT_IDENTITY_TYPE,
            'properties' => PropertyRows::normalize(self::decode($row['properties'])),
            'untrusted' => (bool) $row['untrusted'],
        ];
    }

    /**
     * @param  array<mixed>  $properties
     * @param  CraftUser|bool|null  $savedBy  Who vouches for the Twig: a user (an MCP actor), or true/false from code that knows. Null uses the signed-in user.
     */
    public function saveIdentity(string $type, array $properties, CraftUser|bool|null $savedBy = null): bool
    {
        $this->upsert(self::IDENTITY, [
            'type' => SchemaTypes::cleanName($type) ?: self::DEFAULT_IDENTITY_TYPE,
            'properties' => PropertyRows::normalize($properties),
            'entryTypes' => null,
        ], $savedBy);

        $this->rows = null;

        return true;
    }

    /**
     * Whether Twig saved now can be run. Only an admin, or code that passes `true` because it
     * knows (an admin vouching on the page, an import of trusted rows), is trusted. Nobody signed in,
     * on the console or the web, is not.
     */
    public static function isTrusted(CraftUser|bool|null $savedBy = null): bool
    {
        if (is_bool($savedBy)) {
            return $savedBy;
        }

        $savedBy ??= currentUser();

        return $savedBy instanceof CraftUser && $savedBy->isAdmin();
    }

    /**
     * @param  array{type: string, properties: list<array<string, string>>, entryTypes: array<string, mixed>|null}  $columns
     */
    private function upsert(string $scope, array $columns, CraftUser|bool|null $savedBy): void
    {
        $previous = $this->rows()[$scope] ?? null;

        $hasTwig = self::hasTwig($columns);

        if ($savedBy === true) {
            // An explicit vouch: an admin ticking "Run these values as Twig", or a trusted import
            $untrusted = false;
        } elseif (! self::isTrusted($savedBy)) {
            $untrusted = $hasTwig;
        } else {
            // An admin saving the page doesn't vouch for values they didn't write: it stays
            // untrusted until they say so, or until no value could be Twig
            $untrusted = $previous !== null && $previous['untrusted'] && $hasTwig;
        }

        $now = now();
        $values = [
            'type' => $columns['type'],
            'properties' => Json::encode($columns['properties']),
            'entryTypes' => $columns['entryTypes'] === null ? null : Json::encode((object) $columns['entryTypes']),
            'untrusted' => $untrusted,
            'dateUpdated' => $now,
        ];

        if ($previous === null) {
            DB::table(self::TABLE)->insert(['scope' => $scope] + $values + ['dateCreated' => $now, 'uid' => (string) Str::uuid()]);
        } else {
            DB::table(self::TABLE)->where('scope', $scope)->update($values);
        }
    }

    /**
     * Whether any value in the row could be Twig, whatever its source (a field handle could
     * become a template once its source changes): its own properties and its entry types'.
     *
     * @param  array<string, mixed>  $row
     */
    private static function hasTwig(array $row): bool
    {
        $rows = PropertyRows::normalize(self::decode($row['properties'] ?? null));

        foreach (self::decode($row['entryTypes'] ?? null) as $entryTypeConfig) {
            if (is_array($entryTypeConfig)) {
                array_push($rows, ...PropertyRows::normalize((array) ($entryTypeConfig['properties'] ?? [])));
            }
        }

        foreach ($rows as $propertyRow) {
            if (str_contains($propertyRow['value'], '{')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (! DbSchema::hasTable(self::TABLE)) {
            // Updated but the migration hasn't run yet: keep serving the old config
            return $this->rows = self::legacyRows();
        }

        $rows = [];
        foreach (DB::table(self::TABLE)->get(['scope', 'type', 'properties', 'entryTypes', 'untrusted']) as $row) {
            $rows[(string) $row->scope] = (array) $row;
        }

        return $this->rows = $rows;
    }

    /**
     * Rows built from the project config keys Schemy used before 5.2 and 6.0.0-beta.2.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function legacyRows(): array
    {
        $settings = ProjectConfigHelper::unpackAssociativeArrays((array) (app(ProjectConfig::class)->get('plugins.schemy.settings') ?? []));
        $rows = [];

        foreach ((array) ($settings['sections'] ?? []) as $uid => $config) {
            if (is_string($uid) && is_array($config)) {
                $rows[$uid] = [
                    'type' => SchemaTypes::cleanName((string) ($config['type'] ?? '')),
                    'properties' => (array) ($config['properties'] ?? []),
                    'entryTypes' => array_filter((array) ($config['entryTypes'] ?? []), fn ($c) => is_array($c) && ! empty($c['override'])),
                    'untrusted' => false,
                ];
            }
        }

        if (isset($settings['identity'])) {
            $rows[self::IDENTITY] = [
                'type' => SchemaTypes::cleanName((string) ($settings['identityType'] ?? '')),
                'properties' => (array) $settings['identity'],
                'entryTypes' => null,
                'untrusted' => false,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{type: string, properties: list<array{property: string, source: string, value: string}>, entryTypes: array<string, array{override: true, type: string, properties: list<array{property: string, source: string, value: string}>}>, untrusted: bool}
     */
    private function sectionConfigFromRow(array $row): array
    {
        $entryTypes = [];

        foreach (self::decode($row['entryTypes'] ?? null) as $uid => $entryTypeConfig) {
            if (is_string($uid) && is_array($entryTypeConfig)) {
                $entryTypes[$uid] = [
                    'override' => true,
                    'type' => (string) ($entryTypeConfig['type'] ?? ''),
                    'properties' => PropertyRows::normalize((array) ($entryTypeConfig['properties'] ?? [])),
                ];
            }
        }

        return [
            'type' => (string) ($row['type'] ?? ''),
            'properties' => PropertyRows::normalize(self::decode($row['properties'] ?? null)),
            'entryTypes' => $entryTypes,
            'untrusted' => (bool) ($row['untrusted'] ?? false),
        ];
    }

    /** @return array<mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) && $value !== '' ? Json::decodeIfJson($value) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
