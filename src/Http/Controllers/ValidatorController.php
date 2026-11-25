<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Validator;
use JustinHoltWeb\Schemy\Utilities\ValidatorUtility;

use function CraftCms\Cms\cp_url;

/**
 * Backs the Schema Validator utility's form.
 */
readonly class ValidatorController
{
    public function __construct(private Validator $validator) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $entry = trim((string) $request->input('entry', ''));
        $pasted = (string) $request->input('json', '');
        $siteId = $request->integer('site') ?: null;

        if ($entry !== '') {
            [$result, $json, $error] = ValidatorUtility::validateEntry($entry, $siteId);
        } elseif (trim($pasted) !== '') {
            $validation = $this->validator->validateJson(self::stripScriptTag($pasted));
            $result = $validation->toArray();
            $json = $validation->document === null ? '' : Schema::encode($validation->document);
            $error = null;
        } else {
            [$result, $json, $error] = [null, '', null];
        }

        return redirect(cp_url('utilities/'.ValidatorUtility::id()))->with('schemy.validation', [
            'entry' => $entry,
            'pasted' => $pasted,
            'result' => $result,
            'json' => $json,
            'error' => $error,
        ]);
    }

    /**
     * Accepts JSON wrapped in a `<script type="application/ld+json">` tag, as copied from page source.
     */
    public static function stripScriptTag(string $input): string
    {
        $input = trim($input);

        if (preg_match('#^<script\b[^>]*>(.*)</script>$#is', $input, $match)) {
            return trim($match[1]);
        }

        return $input;
    }
}
