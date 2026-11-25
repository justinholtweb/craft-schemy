<?php

declare(strict_types=1);

use JustinHoltWeb\Schemy\Models\ValidationResult;
use JustinHoltWeb\Schemy\Services\Validator;

function validate(string $json): array
{
    return app(Validator::class)->validateJson($json)->toArray();
}

function messages(array $result, ?string $severity = null): array
{
    return array_values(array_map(
        fn (array $issue) => $issue['message'],
        array_filter($result['issues'], fn (array $issue) => $severity === null || $issue['severity'] === $severity),
    ));
}

it('rejects input that isn’t JSON', function () {
    $result = validate('{nope');

    expect($result['valid'])->toBeFalse()
        ->and(messages($result, ValidationResult::ERROR)[0])->toContain('isn’t valid JSON');
});

it('finds typos, bad dates, relative URLs and HTML', function () {
    $result = validate(json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Artcle', 'headline' => 'x'],
            ['@type' => 'Article', 'headline' => '<p>Hi</p>', 'datePublished' => 'May 1 2026', 'image' => '/rel.jpg', 'autor' => ['@type' => 'Person', 'name' => 'J']],
            ['@type' => 'Event', 'name' => 'E', 'startDate' => '2026-02-30'],
        ],
    ]));

    $all = implode("\n", messages($result));

    expect($all)
        ->toContain('Unknown type `Artcle`. Did you mean `Article`?')
        ->toContain('Unknown property `autor`. Did you mean `author`?')
        ->toContain('`May 1 2026` isn’t an ISO 8601 date')
        ->toContain('`2026-02-30` isn’t an ISO 8601 date')
        ->toContain('`/rel.jpg` isn’t an absolute URL')
        ->toContain('The value contains HTML')
        ->toContain('Event rich results need `location`.');
});

it('checks a mistyped type’s values as the type it was probably meant to be', function () {
    $result = validate('{"@context":"https://schema.org","@type":"Evnt","startDate":"tomorrow"}');

    expect(messages($result, ValidationResult::ERROR))->toContain('`tomorrow` isn’t an ISO 8601 date, like `2026-05-01` or `2026-05-01T09:00:00+00:00`.');
});

it('groups missing recommended properties into one warning', function () {
    $result = validate('{"@context":"https://schema.org","@type":"Event","name":"x","startDate":"2026-05-01","location":"Here"}');

    expect(messages($result, ValidationResult::WARNING))->toBe([
        'Recommended for Event: `description`, `endDate`, `eventAttendanceMode`, `eventStatus`, `image`, `offers`, `organizer`, `performer`.',
    ]);
});

it('accepts either property of an or-requirement', function () {
    $offers = validate('{"@context":"https://schema.org","@type":"Product","name":"x","offers":{"@type":"Offer","price":"1","priceCurrency":"USD","availability":"https://schema.org/InStock","url":"https://e.com/x"}}');
    $none = validate('{"@context":"https://schema.org","@type":"Product","name":"x"}');

    expect(messages($offers, ValidationResult::ERROR))->toBe([])
        ->and(messages($none, ValidationResult::ERROR))->toBe(['Product rich results need `offers` or `review` or `aggregateRating`.']);
});

it('flags one @id used by different types, and dangling references', function () {
    $result = validate(json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'Article', '@id' => 'https://e.com/#a', 'publisher' => ['@id' => 'https://e.com/#org']],
            ['@type' => 'Product', '@id' => 'https://e.com/#a', 'name' => 'x'],
        ],
    ]));

    $all = implode("\n", messages($result));

    expect($all)
        ->toContain('`@id` `https://e.com/#a` is used by nodes of different types (Article, Product)')
        ->toContain('References `https://e.com/#org`, which isn’t defined in this graph.');
});

it('sorts errors first', function () {
    $result = validate('{"@context":"http://schema.org","@type":"Event","name":"x"}');
    $severities = array_column($result['issues'], 'severity');

    expect($severities)->toBe([...array_filter($severities, fn ($s) => $s === 'error'), ...array_filter($severities, fn ($s) => $s === 'warning'), ...array_filter($severities, fn ($s) => $s === 'notice')]);
});
