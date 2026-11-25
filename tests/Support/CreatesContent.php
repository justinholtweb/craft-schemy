<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Tests\Support;

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use JustinHoltWeb\Schemy\Fields\SchemaField;
use JustinHoltWeb\Schemy\Services\Schema;

/**
 * A `news` channel whose `article` entry type has a plain text `summary` and a Schema field
 * `seo`, saved through Craft's own services, with Schemy describing it as a NewsArticle.
 */
trait CreatesContent
{
    protected ?Section $section = null;

    protected ?EntryType $entryType = null;

    protected function section(): Section
    {
        if ($this->section !== null) {
            return $this->section;
        }

        $summary = new PlainText(['name' => 'Summary', 'handle' => 'summary', 'multiline' => true]);
        expect(Fields::saveField($summary))->toBeTrue();

        $schemaField = new SchemaField(['name' => 'SEO Schema', 'handle' => 'seo']);
        expect(Fields::saveField($schemaField))->toBeTrue();

        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([[
            'name' => 'Content',
            'elements' => [new EntryTitleField, new CustomField($summary), new CustomField($schemaField)],
        ]]);

        $this->entryType = new EntryType(['name' => 'Article', 'handle' => 'article']);
        $this->entryType->setFieldLayout($layout);
        expect(EntryTypes::saveEntryType($this->entryType))->toBeTrue();

        $section = new Section([
            'name' => 'News',
            'handle' => 'news',
            'type' => SectionType::Channel,
            'siteSettings' => [
                new SectionSiteSettings([
                    'siteId' => Sites::getPrimarySite()->id,
                    'enabledByDefault' => true,
                    'hasUrls' => true,
                    'uriFormat' => 'news/{slug}',
                    'template' => 'news/_entry',
                ]),
            ],
        ]);
        $section->setEntryTypes([$this->entryType]);
        expect(Sections::saveSection($section))->toBeTrue();

        expect(app(Schema::class)->saveSectionConfig($section, [
            'type' => 'NewsArticle',
            'properties' => [
                ['property' => 'headline', 'source' => 'field', 'value' => 'title'],
                ['property' => 'url', 'source' => 'field', 'value' => 'url'],
                ['property' => 'datePublished', 'source' => 'field', 'value' => 'postDate'],
                ['property' => 'description', 'source' => 'field', 'value' => 'summary'],
                ['property' => 'genre', 'source' => 'template', 'value' => 'Maritime'],
            ],
        ]))->toBeTrue();

        return $this->section = $section;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function createEntry(string $title = 'Harbour reopens', array $fields = []): Entry
    {
        $section = $this->section();

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $this->entryType->id,
            'siteId' => Sites::getPrimarySite()->id,
            'title' => $title,
            'enabled' => true,
        ]);
        $entry->setAuthorIds([$this->admin()->id]);

        foreach ($fields as $handle => $value) {
            $entry->setFieldValue($handle, $value);
        }

        expect(Elements::saveElement($entry))->toBeTrue(json_encode($entry->errors()->getMessages()) ?: '');

        return $entry;
    }

    protected function admin(): User
    {
        return User::find()->admin()->one() ?? throw new \RuntimeException('No admin user.');
    }

    /**
     * @return list<string>
     */
    protected static function types(?array $document): array
    {
        return array_map(fn (array $node) => (string) $node['@type'], $document['@graph'] ?? []);
    }
}
