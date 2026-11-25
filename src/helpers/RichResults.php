<?php

namespace justinholtweb\schemy\helpers;

/**
 * Required and recommended properties for the schema.org types that Google
 * turns into rich results, summarized from Google Search Central's structured
 * data documentation.
 *
 * This is an approximation for catching gaps early. It isn't Google's
 * validator, and Google changes its rules; the Rich Results Test is the final
 * word. A requirement written `a|b` is met by either property.
 *
 * Rules for a type also apply to its subtypes in {@see SchemaTypes}, so the
 * Article rules cover BlogPosting and NewsArticle. Types marked `nested` are
 * checked wherever they appear in the graph (an Offer inside a Product), the
 * rest only as top-level nodes.
 */
class RichResults
{
    private const RULES = [
        'Article' => [
            'required' => [],
            'recommended' => ['author', 'datePublished', 'dateModified', 'headline', 'image'],
        ],
        'BreadcrumbList' => [
            'required' => ['itemListElement'],
            'recommended' => [],
        ],
        'Course' => [
            'required' => ['name', 'description'],
            'recommended' => ['provider', 'offers', 'hasCourseInstance'],
        ],
        'Event' => [
            'required' => ['name', 'startDate', 'location'],
            'recommended' => ['description', 'endDate', 'eventAttendanceMode', 'eventStatus', 'image', 'offers', 'organizer', 'performer'],
        ],
        'FAQPage' => [
            'required' => ['mainEntity'],
            'recommended' => [],
        ],
        'JobPosting' => [
            'required' => ['datePosted', 'description', 'hiringOrganization', 'jobLocation|jobLocationType', 'title'],
            'recommended' => ['applicantLocationRequirements', 'baseSalary', 'directApply', 'employmentType', 'identifier', 'validThrough'],
        ],
        'LocalBusiness' => [
            'required' => ['address', 'name'],
            'recommended' => ['geo', 'image', 'openingHoursSpecification', 'priceRange', 'telephone', 'url'],
        ],
        'Organization' => [
            'required' => [],
            'recommended' => ['name', 'url', 'logo', 'sameAs'],
        ],
        'Product' => [
            'required' => ['name', 'offers|review|aggregateRating'],
            'recommended' => ['brand', 'description', 'image', 'sku'],
        ],
        'Recipe' => [
            'required' => ['image', 'name'],
            'recommended' => ['author', 'datePublished', 'description', 'recipeIngredient', 'recipeInstructions', 'recipeYield', 'totalTime'],
        ],
        'Review' => [
            'required' => ['author', 'itemReviewed', 'reviewRating'],
            'recommended' => ['datePublished'],
        ],
        'SoftwareApplication' => [
            'required' => ['name', 'offers', 'aggregateRating|review'],
            'recommended' => ['applicationCategory', 'operatingSystem'],
        ],
        'VideoObject' => [
            'required' => ['name', 'thumbnailUrl', 'uploadDate'],
            'recommended' => ['contentUrl|embedUrl', 'description', 'duration'],
        ],
        'WebSite' => [
            'required' => [],
            'recommended' => ['name', 'url'],
        ],

        // Checked wherever they appear
        'ListItem' => [
            'nested' => true,
            'required' => ['position', 'name|item'],
            'recommended' => [],
        ],
        'Question' => [
            'nested' => true,
            'required' => ['name', 'acceptedAnswer'],
            'recommended' => [],
        ],
        'Answer' => [
            'nested' => true,
            'required' => ['text'],
            'recommended' => [],
        ],
        'Offer' => [
            'nested' => true,
            'required' => ['price|priceSpecification'],
            'recommended' => ['priceCurrency', 'availability', 'url'],
        ],
        'AggregateRating' => [
            'nested' => true,
            'required' => ['ratingValue', 'ratingCount|reviewCount'],
            'recommended' => [],
        ],
        'Rating' => [
            'nested' => true,
            'required' => ['ratingValue'],
            'recommended' => [],
        ],
    ];

    /**
     * Returns the rule that applies to a type: its own, or its nearest ancestor's.
     *
     * @return array{type: string, required: string[], recommended: string[], nested: bool}|null
     */
    public static function rule(string $type, bool $nested = false): ?array
    {
        $current = $type;

        while ($current !== null) {
            if (isset(self::RULES[$current])) {
                $rule = self::RULES[$current];
                $isNested = $rule['nested'] ?? false;

                if ($nested && !$isNested) {
                    return null;
                }

                return [
                    'type' => $current,
                    'required' => $rule['required'],
                    'recommended' => $rule['recommended'],
                    'nested' => $isNested,
                ];
            }

            $current = SchemaTypes::parent($current);
        }

        return null;
    }

    /**
     * All rules, for documentation and the MCP types tool.
     */
    public static function all(): array
    {
        return array_map(fn(array $rule) => [
            'required' => $rule['required'],
            'recommended' => $rule['recommended'],
            'nested' => $rule['nested'] ?? false,
        ], self::RULES);
    }
}
