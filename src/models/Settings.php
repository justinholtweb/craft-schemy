<?php

namespace justinholtweb\schemy\models;

use craft\base\Model;

/**
 * Plugin settings. Stored in project config, so everything here travels
 * between environments with the rest of your schema.
 *
 * Property rows everywhere share one shape:
 * `['property' => 'headline', 'source' => 'field'|'template', 'value' => 'title']`
 */
class Settings extends Model
{
    /** Output JSON-LD into `<head>` automatically on front-end pages. */
    public bool $autoInject = true;

    /** Include a WebSite node for the current site. */
    public bool $includeWebSite = true;

    /** Include a BreadcrumbList node built from the element's structure ancestors. */
    public bool $includeBreadcrumbs = true;

    /** Include the site identity (Organization, Person, LocalBusiness, …) node. */
    public bool $includeIdentity = true;

    /** schema.org type for the site identity node. */
    public string $identityType = 'Organization';

    /** Property rows for the site identity node. Values are always Twig templates. */
    public array $identity = [
        ['property' => 'name', 'source' => 'template', 'value' => '{{ siteName }}'],
        ['property' => 'url', 'source' => 'template', 'value' => '{{ siteUrl }}'],
    ];

    /**
     * Schema config per section, keyed by section UID:
     *
     * ```php
     * '<sectionUid>' => [
     *     'type' => 'Article',
     *     'properties' => [ ...rows ],
     *     'entryTypes' => [
     *         '<entryTypeUid>' => ['override' => true, 'type' => 'NewsArticle', 'properties' => [ ...rows ]],
     *     ],
     * ]
     * ```
     */
    public array $sections = [];

    protected function defineRules(): array
    {
        return [
            [['identityType'], 'string'],
            [['identityType'], 'match', 'pattern' => '/^[A-Za-z][A-Za-z0-9]*$/'],
        ];
    }
}
