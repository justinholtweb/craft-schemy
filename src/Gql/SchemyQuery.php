<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Gql;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use GraphQL\Type\Definition\Type;
use Illuminate\Support\Facades\Gate;
use JustinHoltWeb\Schemy\Services\Schema;
use Override;

use function CraftCms\Cms\t;

/**
 * The `schemy` query: JSON-LD for an entry or, with no entry, the site-wide nodes.
 *
 * ```graphql
 * { schemy(uri: "news/launch", site: "default") { jsonLd } }
 * ```
 */
class SchemyQuery extends Query
{
    public const string COMPONENT = 'schemy.all';

    #[Override]
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && ! GqlHelper::canSchema(self::COMPONENT)) {
            return [];
        }

        return [
            'schemy' => [
                'type' => SchemyTypes::document(),
                'args' => [
                    'id' => ['name' => 'id', 'type' => Type::int(), 'description' => 'An entry ID.'],
                    'uri' => ['name' => 'uri', 'type' => Type::string(), 'description' => 'An entry URI. Use `__home__` for the home page.'],
                    'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'A site handle. Defaults to the primary site.'],
                    'siteId' => ['name' => 'siteId', 'type' => Type::int(), 'description' => 'A site ID. Defaults to the primary site.'],
                ],
                'resolve' => [self::class, 'resolve'],
                'description' => t('JSON-LD for an entry. With no `id` or `uri`, returns the site-wide nodes (identity and WebSite).', category: 'schemy'),
            ],
        ];
    }

    /**
     * Only entries in sections and sites the schema can read are found, and for an authenticated
     * schema, only ones its user can view. Anything else is null.
     *
     * @param  array<string, mixed>  $args
     * @return array{document: ?array<mixed>}|null
     */
    public static function resolve(mixed $root, array $args): ?array
    {
        $site = match (true) {
            isset($args['siteId']) => Sites::getSiteById((int) $args['siteId']),
            isset($args['site']) => Sites::getSiteByHandle((string) $args['site']),
            default => Sites::getPrimarySite(),
        };

        $allowedSiteIds = array_map(fn (Site $allowed) => $allowed->id, GqlHelper::getAllowedSites());

        if ($site === null || ! in_array($site->id, $allowedSiteIds, true)) {
            return null;
        }

        $entry = null;

        if (isset($args['id']) || isset($args['uri'])) {
            $sectionIds = array_values(array_filter(array_map(
                fn (string $uid) => Sections::getSectionByUid($uid)?->id,
                GqlHelper::extractAllowedEntitiesFromSchema()['sections'] ?? [],
            )));

            if ($sectionIds === []) {
                return null;
            }

            $query = Entry::find()->siteId($site->id)->sectionId($sectionIds);

            if (isset($args['id'])) {
                $query->id((int) $args['id']);
            }

            if (isset($args['uri'])) {
                $query->uri((string) $args['uri']);
            }

            $entry = $query->one();

            if (! $entry instanceof Entry) {
                return null;
            }

            $user = GqlHelper::getSchemaUser();

            if ($user !== null && ! Gate::forUser($user)->allows('view', $entry)) {
                return null;
            }
        }

        return ['document' => app(Schema::class)->getDocumentForElement($entry, $site)];
    }
}
