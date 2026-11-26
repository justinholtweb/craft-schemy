<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\Sections;
use Illuminate\Console\Command;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Store;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports section, entry type and site identity schema as JSON. They live in the database
 * rather than project config, so they don't deploy with the YAML.
 *
 * ```sh
 * php artisan schemy:schemas:export --file=schemy.json
 * ```
 *
 * The file has the same shape as Schemy 5.2's, so either version can import it. Each row says
 * whether it's untrusted (saved by a non-admin, so its Twig isn't run), and an import keeps that.
 */
class ExportSchemasCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'schemy:schemas:export
        {--file= : The file to write. Prints to stdout without it}';

    #[\Override]
    protected $description = 'Exports Schemy’s section schema and site identity as JSON.';

    /** Schemy 5's console command. */
    #[\Override]
    protected $aliases = ['schemy/schemas/export'];

    public function handle(Schema $schema, Store $store): int
    {
        $identity = $store->getIdentity();
        $sections = [];

        foreach (Sections::getAllSections() as $section) {
            /** @var Section $section */
            $config = $schema->getSectionConfig($section);

            if ($config['type'] === '' && $config['entryTypes'] === []) {
                continue;
            }

            $entryTypes = [];
            foreach ($section->getEntryTypes() as $entryType) {
                if (isset($config['entryTypes'][$entryType->uid])) {
                    $entryTypes[$entryType->handle] = [
                        'uid' => $entryType->uid,
                        'type' => $config['entryTypes'][$entryType->uid]['type'],
                        'properties' => $config['entryTypes'][$entryType->uid]['properties'],
                    ];
                }
            }

            $sections[$section->handle] = [
                'uid' => $section->uid,
                'type' => $config['type'],
                'properties' => $config['properties'],
                'entryTypes' => (object) $entryTypes,
                'untrusted' => $config['untrusted'],
            ];
        }

        $json = json_encode([
            'identity' => ['type' => $identity['type'], 'properties' => $identity['properties'], 'untrusted' => $identity['untrusted']],
            'sections' => (object) $sections,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $file = $this->option('file');

        if (! is_string($file) || $file === '') {
            $this->output->write($json, false, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        if (file_put_contents($file, $json) === false) {
            $this->error("Couldn’t write $file");

            return self::FAILURE;
        }

        $this->info(sprintf('Exported the identity and %d sections to %s', count($sections), $file));

        return self::SUCCESS;
    }
}
