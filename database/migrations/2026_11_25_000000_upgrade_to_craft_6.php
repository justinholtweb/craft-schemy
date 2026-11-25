<?php

declare(strict_types=1);

use CraftCms\Cms\Database\Migration;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\ProjectConfig\ProjectConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Schemy\Helpers\LegacyClassMap;

/**
 * Brings a Schemy 5.1 install onto Schemy 6.
 *
 * The Schema field type moved from `justinholtweb\schemy\fields\SchemaField` to
 * `JustinHoltWeb\Schemy\Fields\SchemaField`. Craft stores it in `fields.type` and project
 * config, so both are rewritten. Field values and plugin settings keep their shape.
 *
 * Only what still needs changing is changed, so a second run does nothing, and on a fresh
 * install there is nothing to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $renamed = 0;

        foreach (LegacyClassMap::MAP as $old => $new) {
            $renamed += DB::table(Table::FIELDS)->where('type', $old)->update(['type' => $new]);
        }

        $projectConfig = app(ProjectConfig::class);
        $muteEvents = $projectConfig->muteEvents;
        $projectConfig->muteEvents = true;

        try {
            foreach ((array) $projectConfig->get('fields') as $uid => $config) {
                $updated = LegacyClassMap::replace($config);

                if ($updated !== $config) {
                    $projectConfig->set("fields.$uid", $updated, 'Rename the Schemy field type for Craft 6');
                    $renamed++;
                }
            }

            $projectConfig->saveModifiedConfigData();
        } finally {
            $projectConfig->muteEvents = $muteEvents;
        }

        if ($renamed > 0) {
            Log::info("Schemy: renamed the Schemy 5 field type in $renamed places.");
        }
    }

    public function down(): void {}
};
