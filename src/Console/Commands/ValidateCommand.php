<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Command;
use JustinHoltWeb\Schemy\Helpers\EntryLookup;
use JustinHoltWeb\Schemy\Models\ValidationResult;
use JustinHoltWeb\Schemy\Services\Validator;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Validates the JSON-LD Schemy outputs.
 *
 * ```sh
 * php artisan schemy:validate --section=news
 * php artisan schemy:validate --entry=42 --site=fr
 * php artisan schemy:validate --entry=news/launch
 * php artisan schemy:validate --file=schema.json
 * ```
 *
 * Exits 1 when any error is found, so it can gate a deploy, and 2 on bad usage.
 */
class ValidateCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'schemy:validate
        {--entry= : An entry ID, URI or URL to validate}
        {--section= : A section handle. Validates every live entry in it, up to --limit}
        {--site= : A site handle. Defaults to the primary site}
        {--file= : A JSON-LD file to validate instead of entries}
        {--limit=100 : The most entries to validate with --section}
        {--all : Also print warnings and notices for entries without errors}';

    #[\Override]
    protected $description = 'Validates the JSON-LD Schemy outputs, and fails when it finds an error.';

    /** Schemy 5's console command. */
    #[\Override]
    protected $aliases = ['schemy/validate'];

    public function handle(Validator $validator): int
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            if (! is_file($file)) {
                $this->error("File not found: $file");

                return self::INVALID;
            }

            $result = $validator->validateJson((string) file_get_contents($file));
            $this->report($file, $result, true);

            return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
        }

        $entryRef = (string) $this->option('entry');
        $section = (string) $this->option('section');

        if ($entryRef === '' && $section === '') {
            $this->error('Pass --entry, --section or --file.');

            return self::INVALID;
        }

        $siteHandle = (string) $this->option('site');
        $site = $siteHandle !== '' ? Sites::getSiteByHandle($siteHandle) : Sites::getPrimarySite();

        if ($site === null) {
            $this->error("Site not found: $siteHandle");

            return self::INVALID;
        }

        $entries = $entryRef !== ''
            ? array_filter([EntryLookup::find($entryRef, $site->id)])
            : Entry::find()->siteId($site->id)->section($section)->limit(max(1, (int) $this->option('limit')))->all();

        if ($entries === []) {
            $this->warn('No entries found.');

            return self::INVALID;
        }

        $failed = 0;

        foreach ($entries as $entry) {
            $result = $validator->validateElement($entry);
            $failed += $result->hasErrors() ? 1 : 0;
            $this->report(sprintf('#%d %s', $entry->id, $entry->title), $result, $this->option('all') || $entryRef !== '');
        }

        $this->newLine();
        $message = sprintf('%d checked, %d with errors.', count($entries), $failed);
        $failed ? $this->error($message) : $this->info($message);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(string $label, ValidationResult $result, bool $showAll): void
    {
        $summary = $result->toArray();
        $line = sprintf('%s: %d errors, %d warnings, %d notices', $label, $summary['errors'], $summary['warnings'], $summary['notices']);
        $style = $result->hasErrors() ? 'error' : ($summary['warnings'] ? 'comment' : 'info');
        $this->line("<$style>".$this->escape($line)."</$style>");

        foreach ($summary['issues'] as $issue) {
            if (! $showAll && $issue['severity'] !== ValidationResult::ERROR) {
                continue;
            }

            $this->line(sprintf('  %-7s %s  %s', strtoupper($issue['severity']), $this->escape($issue['path']), $this->escape($issue['message'])));
        }
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
