<?php

namespace justinholtweb\schemy\helpers;

/**
 * A curated slice of the schema.org vocabulary.
 *
 * This isn't the whole vocabulary on purpose. It covers the types that
 * search engines actually do something with, and powers the autocomplete
 * in the control panel. Any type or property name can still be typed in by hand.
 *
 * Property kinds tell the resolver how to shape values pulled from fields:
 * - text:   plain string (rich text is stripped)
 * - url:    URL string (assets/entries become their URL)
 * - date:   ISO 8601 string
 * - image:  assets become ImageObject nodes
 * - person: strings/users/entries become Person nodes
 * - org:    strings/entries become Organization nodes
 * - place:  strings/entries become Place nodes
 */
class SchemaTypes
{
    private const TYPES = [
        'Thing' => [
            'parent' => null,
            'properties' => [
                'name' => 'text', 'alternateName' => 'text', 'description' => 'text', 'url' => 'url',
                'image' => 'image', 'sameAs' => 'url', 'identifier' => 'text', 'mainEntityOfPage' => 'url',
            ],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
            ],
        ],
        'CreativeWork' => [
            'parent' => 'Thing',
            'properties' => [
                'headline' => 'text', 'author' => 'person', 'creator' => 'person', 'publisher' => 'org',
                'datePublished' => 'date', 'dateModified' => 'date', 'dateCreated' => 'date', 'keywords' => 'text',
                'inLanguage' => 'text', 'about' => 'text', 'abstract' => 'text', 'thumbnailUrl' => 'url',
                'copyrightYear' => 'text', 'copyrightHolder' => 'org', 'genre' => 'text', 'text' => 'text',
                'isAccessibleForFree' => 'text', 'license' => 'url', 'video' => 'url',
            ],
            'defaults' => [
                ['headline', 'field', 'title'],
                ['url', 'field', 'url'],
                ['mainEntityOfPage', 'field', 'url'],
                ['datePublished', 'field', 'postDate'],
                ['dateModified', 'field', 'dateUpdated'],
                ['author', 'field', 'author'],
            ],
        ],
        'Article' => [
            'parent' => 'CreativeWork',
            'properties' => ['articleBody' => 'text', 'articleSection' => 'text', 'wordCount' => 'text', 'speakable' => 'text'],
        ],
        'BlogPosting' => ['parent' => 'Article', 'properties' => []],
        'NewsArticle' => ['parent' => 'Article', 'properties' => ['dateline' => 'text', 'printSection' => 'text']],
        'TechArticle' => ['parent' => 'Article', 'properties' => ['proficiencyLevel' => 'text', 'dependencies' => 'text']],
        'WebPage' => [
            'parent' => 'CreativeWork',
            'properties' => [
                'breadcrumb' => 'text', 'primaryImageOfPage' => 'image', 'lastReviewed' => 'date',
                'relatedLink' => 'url', 'significantLink' => 'url', 'mainEntity' => 'text',
            ],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
                ['datePublished', 'field', 'postDate'],
                ['dateModified', 'field', 'dateUpdated'],
            ],
        ],
        'AboutPage' => ['parent' => 'WebPage', 'properties' => []],
        'ContactPage' => ['parent' => 'WebPage', 'properties' => []],
        'CollectionPage' => ['parent' => 'WebPage', 'properties' => []],
        'FAQPage' => ['parent' => 'WebPage', 'properties' => []],
        'ProfilePage' => ['parent' => 'WebPage', 'properties' => []],
        'WebSite' => ['parent' => 'CreativeWork', 'properties' => []],
        'Book' => [
            'parent' => 'CreativeWork',
            'properties' => ['isbn' => 'text', 'numberOfPages' => 'text', 'bookFormat' => 'text', 'illustrator' => 'person', 'bookEdition' => 'text'],
        ],
        'Course' => [
            'parent' => 'CreativeWork',
            'properties' => ['provider' => 'org', 'courseCode' => 'text', 'educationalCredentialAwarded' => 'text', 'hasCourseInstance' => 'text', 'offers' => 'text'],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
            ],
        ],
        'HowTo' => [
            'parent' => 'CreativeWork',
            'properties' => ['step' => 'text', 'totalTime' => 'text', 'prepTime' => 'text', 'performTime' => 'text', 'supply' => 'text', 'tool' => 'text', 'yield' => 'text', 'estimatedCost' => 'text'],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
            ],
        ],
        'Recipe' => [
            'parent' => 'HowTo',
            'properties' => [
                'recipeIngredient' => 'text', 'recipeInstructions' => 'text', 'recipeYield' => 'text', 'cookTime' => 'text',
                'recipeCategory' => 'text', 'recipeCuisine' => 'text', 'nutrition' => 'text', 'suitableForDiet' => 'text',
                'aggregateRating' => 'text',
            ],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
                ['datePublished', 'field', 'postDate'],
                ['author', 'field', 'author'],
            ],
        ],
        'Review' => [
            'parent' => 'CreativeWork',
            'properties' => ['itemReviewed' => 'text', 'reviewRating' => 'text', 'reviewBody' => 'text'],
        ],
        'SoftwareApplication' => [
            'parent' => 'CreativeWork',
            'properties' => ['applicationCategory' => 'text', 'operatingSystem' => 'text', 'softwareVersion' => 'text', 'downloadUrl' => 'url', 'offers' => 'text', 'aggregateRating' => 'text'],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
            ],
        ],
        'VideoObject' => [
            'parent' => 'CreativeWork',
            'properties' => ['contentUrl' => 'url', 'embedUrl' => 'url', 'uploadDate' => 'date', 'duration' => 'text', 'transcript' => 'text'],
            'defaults' => [
                ['name', 'field', 'title'],
                ['url', 'field', 'url'],
                ['uploadDate', 'field', 'postDate'],
            ],
        ],
        'Event' => [
            'parent' => 'Thing',
            'properties' => [
                'startDate' => 'date', 'endDate' => 'date', 'location' => 'place', 'organizer' => 'org', 'performer' => 'person',
                'eventStatus' => 'url', 'eventAttendanceMode' => 'url', 'offers' => 'text', 'doorTime' => 'date', 'isAccessibleForFree' => 'text',
            ],
        ],
        'Organization' => [
            'parent' => 'Thing',
            'properties' => [
                'logo' => 'image', 'legalName' => 'text', 'email' => 'text', 'telephone' => 'text', 'faxNumber' => 'text',
                'address' => 'text', 'foundingDate' => 'date', 'founder' => 'person', 'contactPoint' => 'text',
                'areaServed' => 'text', 'numberOfEmployees' => 'text', 'parentOrganization' => 'org', 'vatID' => 'text', 'taxID' => 'text',
            ],
        ],
        'Corporation' => ['parent' => 'Organization', 'properties' => ['tickerSymbol' => 'text']],
        'EducationalOrganization' => ['parent' => 'Organization', 'properties' => ['alumni' => 'person']],
        'NGO' => ['parent' => 'Organization', 'properties' => []],
        'LocalBusiness' => [
            'parent' => 'Organization',
            'properties' => [
                'openingHours' => 'text', 'openingHoursSpecification' => 'text', 'priceRange' => 'text', 'geo' => 'text',
                'hasMap' => 'url', 'currenciesAccepted' => 'text', 'paymentAccepted' => 'text',
            ],
        ],
        'Restaurant' => ['parent' => 'LocalBusiness', 'properties' => ['servesCuisine' => 'text', 'menu' => 'url', 'acceptsReservations' => 'text']],
        'ProfessionalService' => ['parent' => 'LocalBusiness', 'properties' => []],
        'MedicalBusiness' => ['parent' => 'LocalBusiness', 'properties' => []],
        'Store' => ['parent' => 'LocalBusiness', 'properties' => []],
        'Person' => [
            'parent' => 'Thing',
            'properties' => [
                'givenName' => 'text', 'familyName' => 'text', 'jobTitle' => 'text', 'email' => 'text', 'telephone' => 'text',
                'worksFor' => 'org', 'affiliation' => 'org', 'alumniOf' => 'org', 'birthDate' => 'date', 'knowsAbout' => 'text',
                'honorificPrefix' => 'text', 'honorificSuffix' => 'text', 'address' => 'text',
            ],
        ],
        'Place' => [
            'parent' => 'Thing',
            'properties' => ['address' => 'text', 'geo' => 'text', 'telephone' => 'text', 'hasMap' => 'url', 'openingHoursSpecification' => 'text', 'containedInPlace' => 'place'],
        ],
        'Product' => [
            'parent' => 'Thing',
            'properties' => [
                'brand' => 'org', 'sku' => 'text', 'gtin' => 'text', 'mpn' => 'text', 'offers' => 'text',
                'aggregateRating' => 'text', 'review' => 'text', 'category' => 'text', 'color' => 'text', 'material' => 'text',
                'model' => 'text', 'manufacturer' => 'org', 'releaseDate' => 'date',
            ],
        ],
        'Service' => [
            'parent' => 'Thing',
            'properties' => ['provider' => 'org', 'serviceType' => 'text', 'areaServed' => 'text', 'offers' => 'text', 'category' => 'text', 'audience' => 'text'],
        ],
        'JobPosting' => [
            'parent' => 'Thing',
            'properties' => [
                'title' => 'text', 'datePosted' => 'date', 'validThrough' => 'date', 'employmentType' => 'text',
                'hiringOrganization' => 'org', 'jobLocation' => 'place', 'jobLocationType' => 'text', 'baseSalary' => 'text',
                'applicantLocationRequirements' => 'text', 'directApply' => 'text',
            ],
            'defaults' => [
                ['title', 'field', 'title'],
                ['url', 'field', 'url'],
                ['datePosted', 'field', 'postDate'],
                ['validThrough', 'field', 'expiryDate'],
            ],
        ],
    ];

    /** Identity node types offered on the Site Identity page. */
    public const IDENTITY_TYPES = [
        'Organization', 'Corporation', 'EducationalOrganization', 'NGO', 'LocalBusiness',
        'Restaurant', 'ProfessionalService', 'MedicalBusiness', 'Store', 'Person',
    ];

    /**
     * @return string[] All known type names, alphabetized.
     */
    public static function names(): array
    {
        $names = array_keys(self::TYPES);
        sort($names);
        return $names;
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * Returns every property for a type, including inherited ones, as `name => kind`.
     */
    public static function properties(string $type): array
    {
        $properties = [];

        while ($type !== null && isset(self::TYPES[$type])) {
            $properties += self::TYPES[$type]['properties'];
            $type = self::TYPES[$type]['parent'];
        }

        ksort($properties);
        return $properties;
    }

    /**
     * Returns the kind for a (possibly dotted) property on a type. Unknown properties are `text`.
     */
    public static function kind(?string $type, string $property): string
    {
        if ($type === null || str_contains($property, '.')) {
            return 'text';
        }

        return self::properties($type)[$property] ?? 'text';
    }

    /**
     * Returns the nearest default property rows for a type.
     */
    public static function defaults(string $type): array
    {
        while ($type !== null && isset(self::TYPES[$type])) {
            if (isset(self::TYPES[$type]['defaults'])) {
                return array_map(fn(array $d) => [
                    'property' => $d[0],
                    'source' => $d[1],
                    'value' => $d[2],
                ], self::TYPES[$type]['defaults']);
            }
            $type = self::TYPES[$type]['parent'];
        }

        return [];
    }

    public static function isA(string $type, string $ancestor): bool
    {
        while ($type !== null && isset(self::TYPES[$type])) {
            if ($type === $ancestor) {
                return true;
            }
            $type = self::TYPES[$type]['parent'];
        }

        return false;
    }

    /**
     * The catalogue handed to the control panel JS.
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::names() as $name) {
            $catalog[$name] = [
                'properties' => array_keys(self::properties($name)),
                'defaults' => self::defaults($name),
            ];
        }

        return $catalog;
    }
}
