<?php

declare(strict_types=1);

use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Schemy\Services\Schema;
use JustinHoltWeb\Schemy\Services\Store;

function schemyRows(string $value): array
{
    return [['property' => 'name', 'source' => 'template', 'value' => $value]];
}

it('stores section schema and the identity in its own table, not project config', function () {
    $section = $this->section();
    $schema = app(Schema::class);
    $store = app(Store::class);

    $schema->saveSectionConfig($section, ['type' => 'NewsArticle', 'properties' => schemyRows('{title}')], true);
    $store->saveIdentity('Person', schemyRows('{{ siteName }}'), true);

    expect($schema->getSectionConfig($section)['type'])->toBe('NewsArticle')
        ->and($store->getIdentity()['type'])->toBe('Person')
        ->and(Store::legacyRows())->toBe([]);
});

it('trusts only admins and code that vouches', function () {
    expect(Store::isTrusted(new User(['admin' => true])))->toBeTrue()
        ->and(Store::isTrusted(new User(['admin' => false])))->toBeFalse()
        ->and(Store::isTrusted(true))->toBeTrue()
        ->and(Store::isTrusted(false))->toBeFalse()
        // Nobody signed in, even on the console
        ->and(Store::isTrusted())->toBeFalse();
});

it('keeps a non-admin’s values untrusted until an admin vouches for them', function () {
    $store = app(Store::class);
    $admin = new User(['admin' => true]);
    $editor = new User(['admin' => false]);

    $store->saveIdentity('Organization', schemyRows('{{ siteName }}'), $editor);
    expect($store->getIdentity()['untrusted'])->toBeTrue();

    // Saving the page, even with the values changed, isn't a vouch
    $store->saveIdentity('Organization', schemyRows("{{ siteName }}\r\n"), $admin);
    expect($store->getIdentity()['untrusted'])->toBeTrue();

    $store->saveIdentity('Organization', schemyRows('{{ siteName }}'), true);
    expect($store->getIdentity()['untrusted'])->toBeFalse();

    // Nothing that could be Twig, so nothing to distrust
    $store->saveIdentity('Organization', schemyRows('Plain'), $editor);
    expect($store->getIdentity()['untrusted'])->toBeFalse();
});

it('runs no Twig from untrusted rows, only the site placeholders', function () {
    $section = $this->section();
    $schema = app(Schema::class);

    $schema->saveSectionConfig($section, ['type' => 'NewsArticle', 'properties' => [
        ['property' => 'headline', 'source' => 'field', 'value' => 'title'],
        ['property' => 'genre', 'source' => 'template', 'value' => '{{ entry.author.email }}'],
        ['property' => 'publisher.name', 'source' => 'template', 'value' => '{{ siteName }}'],
    ]], false);

    $node = collect($schema->getDocumentForElement($this->createEntry('Untrusted'))['@graph'])->firstWhere('@type', 'NewsArticle');

    expect($node['headline'])->toBe('Untrusted')
        ->and($node['genre'])->toBe('{{ entry.author.email }}')
        ->and($node['publisher']['name'])->not->toContain('{{');
});
