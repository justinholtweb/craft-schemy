<?php

namespace justinholtweb\schemy\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use justinholtweb\schemy\models\ValidationResult;
use justinholtweb\schemy\Plugin;
use yii\console\ExitCode;

/**
 * Validates the JSON-LD Schemy outputs.
 *
 * ```sh
 * php craft schemy/validate --section=news
 * php craft schemy/validate --entry=42 --site=fr
 * php craft schemy/validate --file=schema.json
 * ```
 *
 * Exits non-zero when any error is found, so it can gate a deploy.
 */
class ValidateController extends Controller
{
    public $defaultAction = 'index';

    /** @var int|null An entry ID to validate. */
    public ?int $entry = null;

    /** @var string|null A section handle. Validates every live entry in it, up to `--limit`. */
    public ?string $section = null;

    /** @var string|null A site handle. Defaults to the primary site. */
    public ?string $site = null;

    /** @var string|null Path to a JSON-LD file to validate instead of entries. */
    public ?string $file = null;

    /** @var int The most entries to validate with `--section`. */
    public int $limit = 100;

    /** @var bool Also print warnings and notices for entries without errors. */
    public bool $all = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'entry', 'section', 'site', 'file', 'limit', 'all'];
    }

    public function actionIndex(): int
    {
        $validator = Plugin::getInstance()->validator;

        if ($this->file !== null) {
            if (!is_file($this->file)) {
                $this->stderr("File not found: $this->file\n", Console::FG_RED);
                return ExitCode::NOINPUT;
            }

            $result = $validator->validateJson((string)file_get_contents($this->file));
            $this->report($this->file, $result, true);

            return $result->hasErrors() ? ExitCode::DATAERR : ExitCode::OK;
        }

        if ($this->entry === null && $this->section === null) {
            $this->stderr("Pass --entry, --section or --file.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $site = $this->site !== null
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();

        if ($site === null) {
            $this->stderr("Site not found: $this->site\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $query = Entry::find()->siteId($site->id);

        if ($this->entry !== null) {
            $query->id($this->entry)->status(null);
        } else {
            $query->section($this->section)->limit($this->limit);
        }

        $entries = $query->all();

        if ($entries === []) {
            $this->stderr("No entries found.\n", Console::FG_YELLOW);
            return ExitCode::NOINPUT;
        }

        $failed = 0;

        foreach ($entries as $entry) {
            $result = $validator->validateElement($entry);
            $failed += $result->hasErrors() ? 1 : 0;
            $this->report(sprintf('#%d %s', $entry->id, $entry->title), $result, $this->all || $this->entry !== null);
        }

        $this->stdout(sprintf("\n%d checked, %d with errors.\n", count($entries), $failed), $failed ? Console::FG_RED : Console::FG_GREEN);

        return $failed ? ExitCode::DATAERR : ExitCode::OK;
    }

    private function report(string $label, ValidationResult $result, bool $showAll): void
    {
        $summary = $result->toArray();
        $color = $result->hasErrors() ? Console::FG_RED : ($summary['warnings'] ? Console::FG_YELLOW : Console::FG_GREEN);

        $this->stdout(sprintf('%s: %d errors, %d warnings, %d notices', $label, $summary['errors'], $summary['warnings'], $summary['notices']) . "\n", $color);

        foreach ($summary['issues'] as $issue) {
            if (!$showAll && $issue['severity'] !== ValidationResult::ERROR) {
                continue;
            }
            $this->stdout(sprintf("  %-7s %s  %s\n", strtoupper($issue['severity']), $issue['path'], $issue['message']));
        }
    }
}
