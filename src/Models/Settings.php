<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Models;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Plugin\PluginSettings;
use DOMDocument;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Schemy's settings: the output switches and the sidebar. The keys are the same as Schemy 5's,
 * so an upgraded site keeps its project config, and `config/craft/schemy.php` overrides them as
 * `config/schemy.php` did.
 *
 * Section, entry type and site identity schema are content, not settings: they live in Schemy's
 * table (`Services\Store`) so permitted users can edit them in any environment.
 *
 * Text settings are nullable: Laravel turns a blank field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** The largest pasted SVG icon accepted, in bytes. */
    public const int MAX_SVG_BYTES = 20000;

    /** Output JSON-LD into `<head>` automatically on front-end pages. */
    public bool $autoInject = true;

    /** Include a WebSite node for the current site. */
    public bool $includeWebSite = true;

    /** Include a BreadcrumbList node built from the element's structure ancestors. */
    public bool $includeBreadcrumbs = true;

    /** Include the site identity (Organization, Person, LocalBusiness, …) node. */
    public bool $includeIdentity = true;

    /** The CP sidebar label. Empty uses "Schemy". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: an icon name from Craft's icon picker. Empty uses Schemy's own. */
    public ?string $navIcon = null;

    /** Raw SVG for the CP sidebar icon. Wins over `navIcon`. Sanitized every time it's drawn. */
    public ?string $navIconSvg = null;

    #[Override]
    public function getRules(): array
    {
        return [
            'autoInject' => ['boolean'],
            'includeWebSite' => ['boolean'],
            'includeBreadcrumbs' => ['boolean'],
            'includeIdentity' => ['boolean'],
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+(:[a-z0-9\-]+)?$/'],
            'navIconSvg' => ['nullable', 'string', 'max:'.self::MAX_SVG_BYTES],
        ];
    }

    #[Override]
    public function getMessages(): array
    {
        return [
            'navIcon.regex' => t('Choose an icon from the icon picker.', category: 'schemy'),
        ];
    }

    /**
     * The checks a rule string can't express: the icon must exist and the SVG must be one `<svg>`.
     */
    #[Override]
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        foreach (['navLabel', 'navIcon', 'navIconSvg'] as $attribute) {
            $value = $this->$attribute;
            $this->$attribute = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        parent::validate($attributeNames, $clearErrors, $throw);
        $errors = $this->errors();

        if ($this->navIcon !== null && ! $errors->has('navIcon') && ! self::iconExists($this->navIcon)) {
            $errors->add('navIcon', t('Choose an icon from the icon picker.', category: 'schemy'));
        }

        if ($this->navIconSvg !== null && ! $errors->has('navIconSvg') && ! self::isSvgMarkup($this->navIconSvg)) {
            $errors->add('navIconSvg', t('Paste a single <svg> element.', category: 'schemy'));
        }

        return $errors->isEmpty();
    }

    public static function iconExists(string $icon): bool
    {
        if (str_contains($icon, ':')) {
            // A custom icon set's `set:name`: checked when drawn
            return true;
        }

        try {
            return is_file(Icons::resolveIconPath($icon));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether the text is one `<svg>` element, optionally after an XML declaration or comments.
     * Checked before the markup goes anywhere near `Html::svg()`, which would otherwise treat a
     * non-SVG string as a file path.
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (! preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $svg) || ! preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // No network, no entity expansion
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }
}
