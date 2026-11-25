<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Utilities;

use CraftCms\Cms\Utility\Utility;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\Schemy\Helpers\EntryLookup;
use JustinHoltWeb\Schemy\Models\ValidationResult;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Validator;
use Override;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Utilities → Schema Validator: checks an entry's JSON-LD or pasted markup.
 *
 * The form posts to `schemy/validator`, which validates and comes back here with the result in
 * the session, so the screen works without any control panel JavaScript. Links such as the one
 * in the entry sidebar open it with `?entry=<id>&site=<id>` and validate straight away.
 */
class ValidatorUtility extends Utility
{
    #[Override]
    public static function displayName(): string
    {
        return t('Schema Validator', category: 'schemy');
    }

    #[Override]
    public static function id(): string
    {
        return 'schemy-validator';
    }

    #[Override]
    public static function icon(): ?string
    {
        return 'list-check';
    }

    #[Override]
    public static function contentHtml(): string
    {
        $flashed = session('schemy.validation');
        $entryRef = (string) request()->query('entry', is_array($flashed) ? ($flashed['entry'] ?? '') : '');
        $siteId = request()->integer('site') ?: null;

        if (is_array($flashed)) {
            $result = $flashed['result'];
            $json = $flashed['json'];
            $error = $flashed['error'];
            $entryRef = $flashed['entry'];
            $pasted = $flashed['pasted'];
        } elseif ($entryRef !== '') {
            [$result, $json, $error] = self::validateEntry($entryRef, $siteId);
            $pasted = '';
        } else {
            [$result, $json, $error, $pasted] = [null, '', null, ''];
        }

        return template('schemy/_validator', [
            'action' => cp_url('schemy/validator'),
            'entry' => $entryRef,
            'site' => $siteId,
            'pasted' => $pasted,
            'result' => $result === null ? null : self::withMessageHtml($result),
            'json' => $json,
            'error' => $error,
        ], TemplateMode::Cp);
    }

    /**
     * Messages mark code with backticks. Everything is escaped, then those spans become `<code>`.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function withMessageHtml(array $result): array
    {
        foreach ($result['issues'] ?? [] as $i => $issue) {
            $html = htmlspecialchars((string) $issue['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $result['issues'][$i]['messageHtml'] = preg_replace('/`([^`]*)`/', '<code>$1</code>', $html) ?? $html;
        }

        return $result;
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string, 2: ?string}
     */
    public static function validateEntry(string $reference, ?int $siteId = null): array
    {
        $entry = EntryLookup::find($reference, $siteId);

        // Same answer whether the entry is missing or not viewable
        if ($entry === null || ! Gate::forUser(currentUser())->allows('view', $entry)) {
            return [null, '', t('No entry matches “{reference}”.', ['reference' => $reference], category: 'schemy')];
        }

        $document = app(Schema::class)->getDocumentForElement($entry);

        if ($document === null) {
            return [(new ValidationResult)->toArray(), '', t('Schemy outputs nothing for this entry.', category: 'schemy')];
        }

        return [app(Validator::class)->validateDocument($document)->toArray(), Schema::encode($document), null];
    }
}
