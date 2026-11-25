<?php

namespace justinholtweb\schemy\gql\queries;

use Craft;
use craft\elements\Entry;
use craft\gql\base\Query;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\schemy\gql\types\SchemyTypes;
use justinholtweb\schemy\Plugin;

/**
 * The `schemy` query: JSON-LD for an entry or, with no entry, the site-wide nodes.
 *
 * ```graphql
 * { schemy(uri: "news/launch", site: "default") { jsonLd } }
 * ```
 */
class SchemyQuery extends Query
{
    public const COMPONENT = 'schemy.all';

    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema(self::COMPONENT)) {
            return [];
        }

        return [
            'schemy' => [
                'type' => SchemyTypes::document(),
                'args' => [
                    'id' => ['type' => Type::int(), 'description' => 'An entry ID.'],
                    'uri' => ['type' => Type::string(), 'description' => 'An entry URI. Use `__home__` for the home page.'],
                    'site' => ['type' => Type::string(), 'description' => 'A site handle. Defaults to the primary site.'],
                    'siteId' => ['type' => Type::int(), 'description' => 'A site ID. Defaults to the primary site.'],
                ],
                'resolve' => [self::class, 'resolve'],
                'description' => Craft::t('schemy', 'JSON-LD for an entry. With no `id` or `uri`, returns the site-wide nodes (identity and WebSite).'),
            ],
        ];
    }

    public static function resolve(mixed $root, array $args): ?array
    {
        $sites = Craft::$app->getSites();
        $site = match (true) {
            isset($args['siteId']) => $sites->getSiteById($args['siteId']),
            isset($args['site']) => $sites->getSiteByHandle($args['site']),
            default => $sites->getPrimarySite(),
        };

        $allowedSiteIds = array_map(fn($allowed) => $allowed->id, GqlHelper::getAllowedSites());

        if ($site === null || !in_array($site->id, $allowedSiteIds, true)) {
            return null;
        }

        $entry = null;

        if (isset($args['id']) || isset($args['uri'])) {
            $sectionUids = GqlHelper::extractAllowedEntitiesFromSchema()['sections'] ?? [];

            if ($sectionUids === []) {
                return null;
            }

            // Only entries in sections this schema can read
            $sectionIds = [];
            foreach ($sectionUids as $uid) {
                $section = Craft::$app->getEntries()->getSectionByUid($uid);
                if ($section !== null) {
                    $sectionIds[] = $section->id;
                }
            }
            $query = Entry::find()->siteId($site->id)->sectionId($sectionIds ?: [0]);

            if (isset($args['id'])) {
                $query->id($args['id']);
            }
            if (isset($args['uri'])) {
                $query->uri($args['uri']);
            }

            $entry = $query->one();

            if ($entry === null) {
                return null;
            }
        }

        return ['document' => Plugin::getInstance()->schema->getDocumentForElement($entry, $site)];
    }
}
