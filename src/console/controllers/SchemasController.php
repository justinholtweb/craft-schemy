<?php

namespace justinholtweb\schemy\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use justinholtweb\schemy\Plugin;
use yii\console\ExitCode;

/**
 * Moves section, entry type and site identity schema between environments. Since 5.2.0 they live
 * in the database rather than project config, so they don't deploy with `project.yaml`.
 *
 * ```sh
 * php craft schemy/schemas/export --file=schemy.json
 * php craft schemy/schemas/import --file=schemy.json
 * ```
 *
 * Each exported row says whether it's untrusted (saved by someone who isn't an admin, so its Twig
 * isn't run), and an import keeps that, so moving schema between environments never trusts it.
 * Rows without the flag import untrusted. Pass `--trusted` to vouch for every row in the file.
 */
class SchemasController extends Controller
{
    /** @var string|null The file to write to or read from. Export prints to stdout without it. */
    public ?string $file = null;

    /** @var bool Import every row as trusted (its Twig is run), whatever the file says. */
    public bool $trusted = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'file', ...($actionID === 'import' ? ['trusted'] : [])];
    }

    /**
     * Exports the site identity and every section's schema as JSON.
     */
    public function actionExport(): int
    {
        $plugin = Plugin::getInstance();
        $identity = $plugin->store->getIdentity();
        $sections = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $config = $plugin->schema->getSectionConfig($section);

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
                'entryTypes' => (object)$entryTypes,
                'untrusted' => $config['untrusted'],
            ];
        }

        $json = Json::encode([
            'identity' => ['type' => $identity['type'], 'properties' => $identity['properties'], 'untrusted' => $identity['untrusted']],
            'sections' => (object)$sections,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if ($this->file === null) {
            $this->stdout($json);
            return ExitCode::OK;
        }

        if (file_put_contents($this->file, $json) === false) {
            $this->stderr("Couldn’t write $this->file\n", Console::FG_RED);
            return ExitCode::CANTCREAT;
        }

        $this->stdout(sprintf("Exported the identity and %d sections to %s\n", count($sections), $this->file), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Imports a file written by `export`. Sections and entry types are matched by UID, then
     * handle; ones this site doesn't have are skipped. Sections not in the file are left alone.
     */
    public function actionImport(): int
    {
        if ($this->file === null || !is_file($this->file)) {
            $this->stderr("Pass the file to import with --file.\n", Console::FG_RED);
            return ExitCode::NOINPUT;
        }

        $data = Json::decodeIfJson((string)file_get_contents($this->file));

        if (!is_array($data)) {
            $this->stderr("$this->file isn’t valid JSON.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $plugin = Plugin::getInstance();

        if (is_array($data['identity'] ?? null)) {
            $plugin->store->saveIdentity(
                (string)($data['identity']['type'] ?? ''),
                (array)($data['identity']['properties'] ?? []),
                $this->vouch($data['identity']),
            );
            $this->stdout("Imported the site identity\n");
        }

        $entries = Craft::$app->getEntries();

        foreach ((array)($data['sections'] ?? []) as $handle => $config) {
            if (!is_array($config)) {
                continue;
            }

            $section = (isset($config['uid']) ? $entries->getSectionByUid((string)$config['uid']) : null)
                ?? $entries->getSectionByHandle((string)$handle);

            if (!$section instanceof Section) {
                $this->stdout("Skipped `$handle`: no such section\n", Console::FG_YELLOW);
                continue;
            }

            $entryTypes = [];
            foreach ((array)($config['entryTypes'] ?? []) as $entryTypeHandle => $entryTypeConfig) {
                $entryType = $this->findEntryType($section, (string)($entryTypeConfig['uid'] ?? ''), (string)$entryTypeHandle);

                if ($entryType === null) {
                    $this->stdout("Skipped `$handle` › `$entryTypeHandle`: the section has no such entry type\n", Console::FG_YELLOW);
                    continue;
                }

                $entryTypes[$entryType->uid] = ['override' => true] + (array)$entryTypeConfig;
            }

            $plugin->schema->saveSectionConfig($section, [
                'type' => (string)($config['type'] ?? ''),
                'properties' => (array)($config['properties'] ?? []),
                'entryTypes' => $entryTypes,
            ], $this->vouch($config));
            $this->stdout("Imported `$section->handle`\n");
        }

        return ExitCode::OK;
    }

    /**
     * Rows are trusted only when the file says they weren't untrusted, or with `--trusted`.
     */
    private function vouch(array $row): bool
    {
        return $this->trusted || ($row['untrusted'] ?? true) === false;
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
