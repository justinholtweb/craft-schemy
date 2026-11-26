<?php

namespace justinholtweb\schemy\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\services\Store;
use Throwable;

/**
 * Moves section, entry type and site identity schema out of project config and into
 * Schemy's own table, so they can be edited where `allowAdminChanges` is off.
 */
class m261126_000000_move_schema_to_database extends Migration
{
    private const KEYS = ['sections', 'identityType', 'identity'];

    public function safeUp(): bool
    {
        Install::createSchemaTable($this);

        $projectConfig = Craft::$app->getProjectConfig();

        // Read the config this environment has applied, not project.yaml: on an environment that
        // receives the change through a deploy, the YAML no longer has these keys
        $settings = ProjectConfigHelper::unpackAssociativeArrays($projectConfig->get('plugins.schemy.settings') ?? []);
        $existing = (new Query())->select(['scope'])->from(Store::TABLE)->column($this->db);

        foreach ((array)($settings['sections'] ?? []) as $uid => $config) {
            if (!is_string($uid) || !is_array($config) || in_array($uid, $existing, true)) {
                continue;
            }

            $entryTypes = [];
            foreach ((array)($config['entryTypes'] ?? []) as $entryTypeUid => $entryTypeConfig) {
                if (is_array($entryTypeConfig) && !empty($entryTypeConfig['override'])) {
                    $entryTypes[$entryTypeUid] = [
                        'type' => SchemaTypes::cleanName((string)($entryTypeConfig['type'] ?? '')),
                        'properties' => PropertyRows::normalize($entryTypeConfig['properties'] ?? []),
                    ];
                }
            }

            $this->insert(Store::TABLE, [
                'scope' => $uid,
                'type' => SchemaTypes::cleanName((string)($config['type'] ?? '')) ?: null,
                'properties' => PropertyRows::normalize($config['properties'] ?? []),
                'entryTypes' => $entryTypes,
                'untrusted' => false,
            ]);
        }

        if (isset($settings['identity']) && !in_array(Store::IDENTITY, $existing, true)) {
            $this->insert(Store::TABLE, [
                'scope' => Store::IDENTITY,
                'type' => SchemaTypes::cleanName((string)($settings['identityType'] ?? '')) ?: 'Organization',
                'properties' => PropertyRows::normalize($settings['identity']),
                'untrusted' => false,
            ]);
        }

        // Drop the old keys from project config once, where the change originates. Environments
        // that get it through project.yaml already have the new schema version there.
        $schemaVersion = $projectConfig->get('plugins.schemy.schemaVersion', true);

        if (version_compare((string)$schemaVersion, '1.1.0', '<')) {
            foreach (self::KEYS as $key) {
                try {
                    $projectConfig->remove("plugins.schemy.settings.$key", 'Move Schemy schema to the database');
                } catch (Throwable $e) {
                    // Harmless: the settings model ignores these keys, and the next settings save drops them
                    Craft::warning("Couldn’t remove `$key` from Schemy’s project config: {$e->getMessage()}", __METHOD__);
                }
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261126_000000_move_schema_to_database cannot be reverted.\n";

        return false;
    }
}
