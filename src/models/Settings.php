<?php

namespace justinholtweb\schemy\models;

use Craft;
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
    /** Longest custom SVG icon accepted, in bytes. */
    public const MAX_SVG_BYTES = 20000;

    /** The control panel sidebar label. Empty uses "Schemy". */
    public ?string $navLabel = null;

    /** The control panel sidebar icon: a name from Craft's icon picker (e.g. `sitemap`). */
    public ?string $navIcon = null;

    /** Raw SVG for the sidebar icon. Wins over `navIcon`. Stored as entered and sanitized every time it's drawn. */
    public ?string $navIconSvg = null;

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
            [['navLabel', 'navIcon', 'navIconSvg'], 'trim'],
            [['navLabel'], 'string', 'max' => 64],
            [['navIcon'], 'validateNavIcon', 'skipOnEmpty' => true],
            [['navIconSvg'], 'string', 'max' => self::MAX_SVG_BYTES],
            [['navIconSvg'], 'validateNavIconSvg', 'skipOnEmpty' => true],
        ];
    }

    public function validateNavIcon(string $attribute): void
    {
        if (!self::iconExists((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('schemy', 'Choose an icon from the icon picker.'));
        }
    }

    public function validateNavIconSvg(string $attribute): void
    {
        if (!self::isSvgMarkup((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('schemy', 'Paste a single <svg> element.'));
        }
    }

    /**
     * Whether an icon name is one of Craft's bundled icons (the set the icon picker offers).
     */
    public static function iconExists(string $name): bool
    {
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            return false;
        }

        foreach (["@appicons/$name.svg", "@app/icons/solid/$name.svg", "@app/icons/custom-icons/$name.svg"] as $alias) {
            $path = Craft::getAlias($alias, false);

            if (is_string($path) && is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text is one `<svg>` element, optionally after an XML declaration, comments or a
     * doctype. Checked before the markup goes anywhere near `Html::svg()`, which would otherwise
     * treat a non-SVG string as a file path.
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (!preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg)
            || !preg_match('/<\/svg>\s*$/i', $svg)
        ) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // No network access, no entity expansion
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }
}
