<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Helpers;

use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;

/**
 * Finds an entry from what a person types: an ID, a URI (`news/launch`) or a full URL on one of
 * the sites. Disabled entries are found too, so they can be checked before they go live.
 */
final class EntryLookup
{
    public static function find(string $reference, ?int $siteId = null): ?Entry
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        if (ctype_digit($reference)) {
            return Entry::find()->id((int) $reference)->siteId($siteId ?? '*')->status(null)->unique()->one();
        }

        [$site, $uri] = preg_match('#^https?://#i', $reference)
            ? self::fromUrl($reference)
            : [$siteId ? Sites::getSiteById($siteId) : Sites::getPrimarySite(), $reference];

        if ($site === null) {
            return null;
        }

        $uri = trim($uri, '/');

        return Entry::find()
            ->siteId($site->id)
            ->uri($uri === '' ? Element::HOMEPAGE_URI : $uri)
            ->status(null)
            ->one();
    }

    /**
     * The site a URL belongs to (the longest matching base URL wins) and the URI within it.
     *
     * @return array{0: ?Site, 1: string}
     */
    private static function fromUrl(string $url): array
    {
        $url = strtok($url, '?#') ?: $url;
        $best = null;
        $bestBase = '';

        foreach (Sites::getAllSites() as $site) {
            $base = rtrim((string) $site->getBaseUrl(), '/');

            if ($base !== '' && (strcasecmp($url, $base) === 0 || stripos($url, $base.'/') === 0) && strlen($base) > strlen($bestBase)) {
                $best = $site;
                $bestBase = $base;
            }
        }

        return [$best, $best ? substr($url, strlen($bestBase)) : ''];
    }
}
