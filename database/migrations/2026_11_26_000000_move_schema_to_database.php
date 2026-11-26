<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JustinHoltWeb\Schemy\Database\Migrations\Install;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;
use JustinHoltWeb\Schemy\Services\Store;

/**
 * Moves section, entry type and site identity schema out of project config and into Schemy's
 * table, as Schemy 5.2 does. Sites upgraded from 5.2 have already done this, so it only finds
 * something to move on a site that installed 6.0.0-beta.1.
 */
return new class extends Migration
{
    private const array KEYS = ['sections', 'identityType', 'identity'];

    public function up(): void
    {
        // Craft loads Install.php by path rather than through the autoloader
        if (! class_exists(Install::class)) {
            require_once __DIR__.'/Install.php';
        }

        Install::createSchemaTable();

        // The config this environment has applied, not the YAML, which a deploy may have updated
        $legacy = Store::legacyRows();
        $existing = DB::table(Store::TABLE)->pluck('scope')->all();
        $now = now();

        foreach ($legacy as $scope => $row) {
            if (in_array($scope, $existing, true)) {
                continue;
            }

            $entryTypes = null;
            if ($scope !== Store::IDENTITY) {
                $entryTypes = [];
                foreach ((array) $row['entryTypes'] as $uid => $config) {
                    $entryTypes[$uid] = [
                        'type' => SchemaTypes::cleanName((string) ($config['type'] ?? '')),
                        'properties' => PropertyRows::normalize((array) ($config['properties'] ?? [])),
                    ];
                }
            }

            DB::table(Store::TABLE)->insert([
                'scope' => $scope,
                'type' => $row['type'] ?: ($scope === Store::IDENTITY ? Store::DEFAULT_IDENTITY_TYPE : null),
                'properties' => json_encode(PropertyRows::normalize((array) $row['properties'])),
                'entryTypes' => $entryTypes === null ? null : json_encode((object) $entryTypes),
                'untrusted' => false,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => (string) Str::uuid(),
            ]);
        }

        $projectConfig = app(ProjectConfig::class);

        if (version_compare((string) $projectConfig->get('plugins.schemy.schemaVersion', true), '1.1.0', '<')) {
            foreach (self::KEYS as $key) {
                try {
                    $projectConfig->remove("plugins.schemy.settings.$key", 'Move Schemy schema to the database');
                } catch (Throwable $e) {
                    // Harmless: the settings model ignores these keys, and the next settings save drops them
                    Log::warning("Couldn’t remove `$key` from Schemy’s project config: {$e->getMessage()}");
                }
            }
        }
    }

    public function down(): void {}
};
