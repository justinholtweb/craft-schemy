<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Database\Migrations;

use CraftCms\Cms\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Schemy\Services\Store;

/**
 * Creates the table for section, entry type and site identity schema. Sites upgraded from
 * Schemy 5.2 already have it, with the same columns.
 */
class Install extends Migration
{
    public function up(): void
    {
        self::createSchemaTable();
    }

    public function down(): void
    {
        Schema::dropIfExists(Store::TABLE);
    }

    public static function createSchemaTable(): void
    {
        if (Schema::hasTable(Store::TABLE)) {
            return;
        }

        Schema::create(Store::TABLE, function (Blueprint $table): void {
            $table->integer('id', true);
            // `identity`, or a section UID
            $table->string('scope', 64)->unique();
            $table->string('type')->nullable();
            $table->json('properties')->nullable();
            $table->json('entryTypes')->nullable();
            // Saved by someone who isn't an admin, unvouched: Twig in its values isn't run
            $table->boolean('untrusted')->default(false);
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');
        });
    }
}
