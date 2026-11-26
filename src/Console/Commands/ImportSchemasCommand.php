<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Sections;
use Illuminate\Console\Command;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Store;

/**
 * Imports a file written by `schemy:schemas:export` (or Schemy 5.2's `schemy/schemas/export`).
 *
 * ```sh
 * php artisan schemy:schemas:import --file=schemy.json
 * ```
 *
 * Sections and entry types are matched by UID, then handle; ones this site doesn't have are
 * skipped, and sections not in the file are left alone. Rows keep their `untrusted` flag, and
 * rows without one import untrusted, so moving schema never trusts it. `--trusted`
 * vouches for every row in the file.
 */
class ImportSchemasCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'schemy:schemas:import
        {--file= : The file to import}
        {--trusted : Import every row as trusted (its Twig is run), whatever the file says}';

    #[\Override]
    protected $description = 'Imports Schemy’s section schema and site identity from JSON.';

    /** Schemy 5's console command. */
    #[\Override]
    protected $aliases = ['schemy/schemas/import'];

    public function handle(Schema $schema, Store $store): int
    {
        $file = $this->option('file');

        if (! is_string($file) || ! is_file($file)) {
            $this->error('Pass the file to import with --file.');

            return self::INVALID;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            $this->error("$file isn’t valid JSON.");

            return self::FAILURE;
        }

        if (is_array($data['identity'] ?? null)) {
            $store->saveIdentity(
                (string) ($data['identity']['type'] ?? ''),
                (array) ($data['identity']['properties'] ?? []),
                $this->vouch($data['identity']),
            );
            $this->line('Imported the site identity');
        }

        foreach ((array) ($data['sections'] ?? []) as $handle => $config) {
            if (! is_array($config)) {
                continue;
            }

            $section = (isset($config['uid']) ? Sections::getSectionByUid((string) $config['uid']) : null)
                ?? Sections::getSectionByHandle((string) $handle);

            if (! $section instanceof Section) {
                $this->warn("Skipped `$handle`: no such section");

                continue;
            }

            $entryTypes = [];
            foreach ((array) ($config['entryTypes'] ?? []) as $entryTypeHandle => $entryTypeConfig) {
                $entryType = $this->findEntryType($section, (string) ($entryTypeConfig['uid'] ?? ''), (string) $entryTypeHandle);

                if ($entryType === null) {
                    $this->warn("Skipped `$handle` › `$entryTypeHandle`: the section has no such entry type");

                    continue;
                }

                $entryTypes[$entryType->uid] = ['override' => true] + (array) $entryTypeConfig;
            }

            $schema->saveSectionConfig($section, [
                'type' => (string) ($config['type'] ?? ''),
                'properties' => (array) ($config['properties'] ?? []),
                'entryTypes' => $entryTypes,
            ], $this->vouch($config));
            $this->line("Imported `$section->handle`");
        }

        return self::SUCCESS;
    }

    /**
     * Rows are trusted only when the file says they weren't untrusted, or with `--trusted`.
     *
     * @param  array<mixed>  $row
     */
    private function vouch(array $row): bool
    {
        return (bool) $this->option('trusted') || ($row['untrusted'] ?? true) === false;
    }

    private function findEntryType(Section $section, string $uid, string $handle): ?EntryType
    {
        $entryTypes = $section->getEntryTypes();

        foreach ($entryTypes as $entryType) {
            if ($entryType->uid === $uid) {
                return $entryType;
            }
        }

        foreach ($entryTypes as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        return null;
    }
}
