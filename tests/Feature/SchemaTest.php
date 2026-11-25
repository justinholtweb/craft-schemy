<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Schemy\Events\GraphResolving;
use JustinHoltWeb\Schemy\Models\SchemaOverride;
use JustinHoltWeb\Schemy\Services\Schema;

it('builds the linked graph for an entry from its section', function () {
    $entry = $this->createEntry('Harbour reopens', ['summary' => 'The <p>harbour</p> reopens.']);
    $document = app(Schema::class)->getDocumentForElement($entry);

    expect(self::types($document))->toBe(['Organization', 'WebSite', 'NewsArticle', 'BreadcrumbList']);

    $main = $document['@graph'][2];

    expect($main)->toMatchArray([
        '@id' => $entry->getUrl().'#main',
        'headline' => 'Harbour reopens',
        'description' => 'The harbour reopens.',
        'genre' => 'Maritime',
    ])->and($main['publisher'])->toBe(['@id' => $document['@graph'][0]['@id']]);
});

it('layers an entry’s rows over the inherited ones when the type matches', function () {
    $entry = $this->createEntry('Layered', ['seo' => [
        'mode' => SchemaOverride::MODE_OVERRIDE,
        'type' => 'NewsArticle',
        'properties' => [['property' => 'genre', 'source' => 'template', 'value' => 'Weather']],
    ]]);

    $main = app(Schema::class)->getDocumentForElement($entry)['@graph'][2];

    expect($main['headline'])->toBe('Layered')->and($main['genre'])->toBe('Weather');
});

it('starts fresh when an entry picks a different type, and keeps entry text literal', function () {
    $entry = $this->createEntry('Fresh', ['seo' => [
        'mode' => SchemaOverride::MODE_OVERRIDE,
        'type' => 'Event',
        'properties' => [['property' => 'name', 'source' => 'template', 'value' => '{{ entry.title }}']],
    ]]);

    $main = app(Schema::class)->getDocumentForElement($entry)['@graph'][2];

    expect($main['@type'])->toBe('Event')
        ->and($main)->not->toHaveKey('headline')
        ->and($main['name'])->toBe('{{ entry.title }}');
});

it('drops only the entry’s own node when disabled, keeping extra JSON-LD', function () {
    $entry = $this->createEntry('Quiet', ['seo' => [
        'mode' => SchemaOverride::MODE_DISABLE,
        'extraJson' => '{"@type": "FAQPage", "mainEntity": []}',
    ]]);

    expect(self::types(app(Schema::class)->getDocumentForElement($entry)))->toBe(['Organization', 'WebSite', 'FAQPage', 'BreadcrumbList']);
});

it('rejects extra JSON-LD that isn’t an object or list of objects', function () {
    $entry = $this->createEntry();
    $entry->setFieldValue('seo', ['mode' => 'inherit', 'extraJson' => '{not json']);

    expect($entry->validate())->toBeFalse();
});

it('lets listeners change the graph', function () {
    Event::listen(function (GraphResolving $event) {
        $event->graph[] = ['@type' => 'SiteNavigationElement', 'name' => 'Main'];
    });

    expect(self::types(app(Schema::class)->getDocument()))->toBe(['Organization', 'WebSite', 'SiteNavigationElement']);
});

it('escapes </script> in the script tag', function () {
    $schema = app(Schema::class);
    $schema->addNode(['@type' => 'Event', 'name' => 'Launch </script> party']);
    $script = $schema->renderScript();

    expect($script)->toStartWith('<script type="application/ld+json">')
        ->and($script)->not->toContain('Launch </script>')
        ->and($script)->toContain('Launch \\u003C/script\\u003E party');
});

it('skips automatic output once a template rendered or disabled it', function () {
    $schema = app(Schema::class);
    expect($schema->pendingScript())->toContain('WebSite');

    $rendered = new Schema;
    $rendered->renderScript();
    expect($rendered->pendingScript())->toBeNull();

    $disabled = new Schema;
    $disabled->disable();
    expect($disabled->pendingScript())->toBeNull();
});

it('keeps only entry types that belong to the section when saving', function () {
    $section = $this->section();
    $schema = app(Schema::class);

    $schema->saveSectionConfig($section, [
        'type' => 'NewsArticle',
        'entryTypes' => [
            $this->entryType->uid => ['override' => true, 'type' => 'BlogPosting', 'properties' => []],
            'not-a-uid' => ['override' => true, 'type' => 'Event'],
        ],
    ]);

    expect(array_keys($schema->getSectionConfig($section)['entryTypes']))->toBe([$this->entryType->uid])
        ->and(self::types($schema->getDocumentForElement($this->createEntry('Posting'))))->toContain('BlogPosting');
});
