<?php

namespace justinholtweb\schemy\migrations;

use craft\db\Migration;
use justinholtweb\schemy\services\Store;

/**
 * Creates the table that holds section, entry type and site identity schema.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        self::createSchemaTable($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Store::TABLE);

        return true;
    }

    /**
     * Shared with the 5.2.0 upgrade migration.
     */
    public static function createSchemaTable(Migration $migration): void
    {
        if ($migration->db->tableExists(Store::TABLE)) {
            return;
        }

        $migration->createTable(Store::TABLE, [
            'id' => $migration->primaryKey(),
            // `identity`, or a section UID
            'scope' => $migration->string(64)->notNull(),
            'type' => $migration->string()->null(),
            'properties' => $migration->json()->null(),
            'entryTypes' => $migration->json()->null(),
            // Saved by someone who isn't an admin, unvouched: Twig in its values isn't run
            'untrusted' => $migration->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Store::TABLE, ['scope'], true);
    }
}
