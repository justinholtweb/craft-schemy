<?php

namespace justinholtweb\schemy\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\data\OptionData;
use craft\helpers\ArrayHelper;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\models\Site;
use craft\web\View;
use DateTimeInterface;
use Illuminate\Support\Collection;
use justinholtweb\schemy\events\GraphEvent;
use justinholtweb\schemy\fields\SchemaField;
use justinholtweb\schemy\helpers\PropertyRows;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\models\SchemaOverride;
use justinholtweb\schemy\Plugin;
use Throwable;
use Twig\Markup;

/**
 * Builds the JSON-LD graph for a page.
 */
class Schema extends Component
{
    /**
     * @event GraphEvent Fired before the graph is encoded.
     */
    public const EVENT_BEFORE_RENDER_GRAPH = 'beforeRenderGraph';

    /**
     * Element attributes that can be mapped without being in the field layout.
     */
    public const BUILT_IN_ATTRIBUTES = [
        'title' => 'Title',
        'url' => 'URL',
        'slug' => 'Slug',
        'postDate' => 'Post Date',
        'dateCreated' => 'Date Created',
        'dateUpdated' => 'Date Updated',
        'expiryDate' => 'Expiry Date',
        'author' => 'Author',
    ];

    private const MAX_DEPTH = 4;

    /** @var array[] Nodes added from templates via `craft.schemy.add()` */
    private array $extraNodes = [];
    private bool $disabled = false;
    private bool $rendered = false;
    private ElementInterface|false|null $elementOverride = null;

    // Request state (driven by craft.schemy in templates)
    // -------------------------------------------------------------------------

    public function addNode(array $node): void
    {
        $this->extraNodes[] = $node;
    }

    public function disable(): void
    {
        $this->disabled = true;
    }

    /**
     * Sets the element auto-injection should describe. Pass `false` to output
     * only the site-wide nodes.
     */
    public function setElement(ElementInterface|false|null $element): void
    {
        $this->elementOverride = $element;
    }

    /**
     * Registers the page's JSON-LD in `<head>` unless it was already rendered
     * manually or disabled. Called at the end of every front-end page template.
     */
    public function injectIntoView(View $view): void
    {
        if ($this->disabled || $this->rendered) {
            return;
        }

        $element = $this->elementOverride ?? Craft::$app->getUrlManager()->getMatchedElement();
        $script = $this->renderScript($element ?: null);

        if ($script !== '') {
            $view->registerHtml($script, View::POS_HEAD, 'schemy');
        }
    }

    /**
     * Returns the full `<script type="application/ld+json">` tag.
     */
    public function renderScript(?ElementInterface $element = null): string
    {
        $this->rendered = true;

        if ($this->disabled) {
            return '';
        }

        $document = $this->getDocument($element);

        if ($document === null) {
            return '';
        }

        // JSON_HEX_TAG keeps "</script>" in content from closing the tag early
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT);

        return $json === false ? '' : Html::tag('script', $json, ['type' => 'application/ld+json']);
    }

    /**
     * Returns the JSON-LD document (`@context` + `@graph`), or null if there's nothing to output.
     */
    public function getDocument(?ElementInterface $element = null): ?array
    {
        $graph = $this->getGraph($element);

        if (empty($graph)) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * Returns the list of nodes for the page.
     *
     * @return array[]
     */
    public function getGraph(?ElementInterface $element = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $site = $element?->getSite() ?? Craft::$app->getSites()->getCurrentSite();
        $siteUrl = $this->siteUrl($site);
        $identityId = $siteUrl . '#identity';
        $graph = [];

        $identity = null;
        if ($settings->includeIdentity) {
            $identity = $this->buildIdentityNode($site, $identityId);
            if ($identity !== null) {
                $graph[] = $identity;
            }
        }

        if ($settings->includeWebSite) {
            $graph[] = array_filter([
                '@type' => 'WebSite',
                '@id' => $siteUrl . '#website',
                'url' => $siteUrl,
                'name' => $site->getName(),
                'inLanguage' => $site->language,
                'publisher' => $identity ? ['@id' => $identityId] : null,
            ]);
        }

        if ($element !== null) {
            $override = $this->getOverride($element);

            if ($override?->mode !== SchemaOverride::MODE_DISABLE) {
                $main = $this->buildMainNode($element, $override);

                if ($main !== null) {
                    if ($identity && !isset($main['publisher']) && isset(SchemaTypes::properties($main['@type'])['publisher'])) {
                        $main['publisher'] = ['@id' => $identityId];
                    }
                    $graph[] = $main;
                }
            }

            foreach ($override?->getExtraNodes() ?? [] as $node) {
                $graph[] = $node;
            }

            if ($settings->includeBreadcrumbs) {
                $breadcrumbs = $this->buildBreadcrumbs($element, $site, $siteUrl);
                if ($breadcrumbs !== null) {
                    $graph[] = $breadcrumbs;
                }
            }
        }

        foreach ($this->extraNodes as $node) {
            $graph[] = $node;
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_RENDER_GRAPH)) {
            $event = new GraphEvent(['element' => $element, 'graph' => $graph]);
            $this->trigger(self::EVENT_BEFORE_RENDER_GRAPH, $event);
            $graph = $event->graph;
        }

        return array_values($graph);
    }

    // Config resolution
    // -------------------------------------------------------------------------

    /**
     * Returns the element's Schemy field value, if its field layout has one.
     */
    public function getOverride(ElementInterface $element): ?SchemaOverride
    {
        $field = $this->findSchemaField($element->getFieldLayout());

        if ($field === null) {
            return null;
        }

        try {
            $value = $element->getFieldValue($field->handle);
        } catch (Throwable) {
            return null;
        }

        return $value instanceof SchemaOverride ? $value : null;
    }

    public function findSchemaField(?FieldLayout $layout): ?SchemaField
    {
        foreach ($layout?->getCustomFields() ?? [] as $field) {
            if ($field instanceof SchemaField) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Returns the type/properties an element gets from its section and entry type,
     * ignoring any per-entry override.
     *
     * @return array{type: string, properties: array, from: string}|null
     */
    public function getInheritedConfig(ElementInterface $element): ?array
    {
        if (!$element instanceof Entry) {
            return null;
        }

        $section = $element->getSection();

        if ($section === null) {
            return null;
        }

        $config = Plugin::getInstance()->getSettings()->sections[$section->uid] ?? null;

        if (!is_array($config)) {
            return null;
        }

        $entryTypeConfig = $config['entryTypes'][$element->getType()->uid] ?? null;

        if (is_array($entryTypeConfig) && !empty($entryTypeConfig['override'])) {
            $type = trim((string)($entryTypeConfig['type'] ?? ''));
            return $type === '' ? null : [
                'type' => $type,
                'properties' => PropertyRows::normalize($entryTypeConfig['properties'] ?? []),
                'from' => Craft::t('schemy', '{section} › {entryType}', [
                    'section' => $section->name,
                    'entryType' => $element->getType()->name,
                ]),
            ];
        }

        $type = trim((string)($config['type'] ?? ''));

        return $type === '' ? null : [
            'type' => $type,
            'properties' => PropertyRows::normalize($config['properties'] ?? []),
            'from' => $section->name,
        ];
    }

    /**
     * Builds the element's primary node.
     *
     * When an entry overrides with the same type it inherits, its rows are
     * layered over the inherited rows. A different type starts fresh.
     */
    public function buildMainNode(ElementInterface $element, ?SchemaOverride $override = null): ?array
    {
        $inherited = $this->getInheritedConfig($element);
        $type = $inherited['type'] ?? null;
        $rows = $inherited['properties'] ?? [];
        $overrideRows = [];

        if ($override?->mode === SchemaOverride::MODE_OVERRIDE) {
            $overrideType = $override->type ?: $type;

            if ($overrideType !== $type) {
                $rows = [];
            }

            $type = $overrideType;
            $overrideRows = $override->properties;
        }

        if ($type === null) {
            return null;
        }

        $allowTwig = $this->findSchemaField($element->getFieldLayout())?->allowTwig ?? false;
        $node = $this->buildNode($type, $rows, $element);

        // Entry-level rows replace inherited values for the same property
        $entryNode = $this->buildNode($type, $overrideRows, $element, $allowTwig);
        unset($entryNode['@type']);
        $node = array_merge($node, $entryNode);

        if (!isset($node['@id']) && ($url = $element->getUrl())) {
            $node = ['@type' => $node['@type'], '@id' => $url . '#main'] + $node;
        }

        return $node;
    }

    public function buildIdentityNode(Site $site, string $id): ?array
    {
        $settings = Plugin::getInstance()->getSettings();
        $type = $settings->identityType ?: 'Organization';
        $node = $this->buildNode($type, PropertyRows::normalize($settings->identity), $site);

        if (count($node) <= 1) {
            return null;
        }

        return ['@type' => $node['@type'], '@id' => $id] + $node;
    }

    /**
     * Builds a node from property rows.
     *
     * @param ElementInterface|Site|null $object What field values and object templates are read from
     * @param bool $parseTwig Whether template values are rendered as Twig or used literally
     */
    public function buildNode(string $type, array $rows, ElementInterface|Site|null $object, bool $parseTwig = true): array
    {
        $node = ['@type' => $type];

        foreach ($rows as $row) {
            $kind = SchemaTypes::kind($type, $row['property']);

            if ($row['source'] === PropertyRows::SOURCE_FIELD) {
                $value = $object instanceof ElementInterface ? $this->fieldValue($object, $row['value'], $kind) : null;
            } else {
                $value = $this->templateValue($row['value'], $object, $kind, $parseTwig);
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $this->setPath($node, $row['property'], $value);
        }

        return $node;
    }

    /**
     * Options for the "Field" source dropdown, given the field layouts in play.
     *
     * @param array<FieldLayout|null> $layouts
     * @return array<array{label: string, value: string}>
     */
    public function fieldOptions(array $layouts): array
    {
        $options = [];

        foreach (self::BUILT_IN_ATTRIBUTES as $handle => $label) {
            $options[$handle] = ['label' => Craft::t('app', $label), 'value' => $handle];
        }

        foreach ($layouts as $layout) {
            foreach ($layout?->getCustomFields() ?? [] as $field) {
                /** @var FieldInterface $field */
                if ($field instanceof SchemaField || isset($options[$field->handle])) {
                    continue;
                }
                $options[$field->handle] = [
                    'label' => sprintf('%s (%s)', $field->name, $field->handle),
                    'value' => $field->handle,
                ];
            }
        }

        return array_values($options);
    }

    // Value resolution
    // -------------------------------------------------------------------------

    private function fieldValue(ElementInterface $element, string $handle, string $kind): mixed
    {
        if ($handle === '') {
            return null;
        }

        try {
            if ($element->getFieldLayout()?->getFieldByHandle($handle) !== null) {
                $raw = $element->getFieldValue($handle);
            } elseif (isset(self::BUILT_IN_ATTRIBUTES[$handle]) && $element->canGetProperty($handle)) {
                $raw = ArrayHelper::getValue($element, $handle);
            } else {
                return null;
            }

            return $this->normalize($raw, $kind);
        } catch (Throwable $e) {
            Craft::warning("Schemy couldn't read `$handle`: {$e->getMessage()}", __METHOD__);
            return null;
        }
    }

    private function templateValue(string $template, ElementInterface|Site|null $object, string $kind, bool $parseTwig): mixed
    {
        if (trim($template) === '') {
            return null;
        }

        if ($parseTwig) {
            try {
                $output = Craft::$app->getView()->renderObjectTemplate($template, $object ?? new \stdClass(), [
                    'entry' => $object instanceof Entry ? $object : null,
                    'element' => $object instanceof ElementInterface ? $object : null,
                ]);
            } catch (Throwable $e) {
                Craft::warning("Schemy couldn't render template `$template`: {$e->getMessage()}", __METHOD__);
                return null;
            }
        } else {
            $output = $template;
        }

        $output = trim($output);

        if ($output === '') {
            return null;
        }

        // Templates can emit JSON for nested structures, e.g. {{ {...}|json_encode }}
        if ($output[0] === '{' || $output[0] === '[') {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->coerceString($output, $kind);
    }

    /**
     * Turns a raw field value into something JSON-LD friendly.
     */
    private function normalize(mixed $value, string $kind, int $depth = 0): mixed
    {
        if ($value === null || $depth > self::MAX_DEPTH) {
            return null;
        }

        if ($value instanceof ElementQueryInterface) {
            $value = $value->all();
        } elseif ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Asset) {
            return $this->normalizeAsset($value, $kind);
        }

        if ($value instanceof User) {
            $name = trim((string)$value->fullName);
            return $name === '' ? null : ['@type' => 'Person', 'name' => $name];
        }

        if ($value instanceof ElementInterface) {
            return $this->normalizeElement($value, $kind);
        }

        if ($value instanceof OptionData) {
            return ($value->value === null || $value->value === '') ? null : (string)$value->label;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->coerceString($this->cleanText($value), $kind);
        }

        if ($value instanceof Markup) {
            return $this->coerceString($this->cleanText((string)$value), $kind);
        }

        if (is_iterable($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $normalized = $this->normalize($item, $kind, $depth + 1);
                if ($normalized !== null && $normalized !== '' && $normalized !== []) {
                    $items[$key] = $normalized;
                }
            }

            if ($items === []) {
                return null;
            }

            // Lists (relations, multi-selects) stay lists even after dropping empty items
            $isList = array_filter(array_keys($items), 'is_string') === [];

            if ($isList) {
                $items = array_values($items);
                return count($items) === 1 ? $items[0] : $items;
            }

            return $items;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->coerceString($this->cleanText((string)$value), $kind);
        }

        return null;
    }

    private function normalizeAsset(Asset $asset, string $kind): mixed
    {
        $url = $asset->getUrl();

        if (!$url) {
            return null;
        }

        if ($kind !== 'image' || $asset->kind !== Asset::KIND_IMAGE) {
            return $url;
        }

        return array_filter([
            '@type' => 'ImageObject',
            'url' => $url,
            'width' => $asset->getWidth(),
            'height' => $asset->getHeight(),
            'caption' => $asset->alt ?: null,
        ]);
    }

    private function normalizeElement(ElementInterface $element, string $kind): mixed
    {
        $name = trim((string)$element->title) ?: (string)$element;
        $url = $element->getUrl();

        return match ($kind) {
            'url', 'image' => $url,
            'person', 'org', 'place' => array_filter([
                '@type' => $this->kindType($kind),
                'name' => $name,
                'url' => $url,
            ]),
            default => $name !== '' ? $name : null,
        };
    }

    private function coerceString(string $value, string $kind): mixed
    {
        if ($value === '') {
            return null;
        }

        return match ($kind) {
            'person', 'org', 'place' => ['@type' => $this->kindType($kind), 'name' => $value],
            default => $value,
        };
    }

    private function kindType(string $kind): string
    {
        return match ($kind) {
            'person' => 'Person',
            'org' => 'Organization',
            default => 'Place',
        };
    }

    private function cleanText(string $value): string
    {
        if (str_contains($value, '<')) {
            $value = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $value));
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Sets a value at a dot path. Repeated properties become arrays,
     * so mapping `sameAs` three times yields three URLs.
     */
    private function setPath(array &$node, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $target = &$node;

        foreach ($keys as $key) {
            if (!isset($target[$key]) || !is_array($target[$key]) || array_is_list($target[$key])) {
                $target[$key] = [];
            }
            $target = &$target[$key];
        }

        if (!array_key_exists($last, $target)) {
            $target[$last] = $value;
        } elseif (is_array($target[$last]) && array_is_list($target[$last])) {
            $target[$last][] = $value;
        } else {
            $target[$last] = [$target[$last], $value];
        }
    }

    // Breadcrumbs
    // -------------------------------------------------------------------------

    private function buildBreadcrumbs(ElementInterface $element, Site $site, string $siteUrl): ?array
    {
        $url = $element->getUrl();

        if (!$url || $element->uri === Element::HOMEPAGE_URI) {
            return null;
        }

        $crumbs = [['name' => $site->getName(), 'url' => $siteUrl]];

        if ($element instanceof Entry && $element->getSection()?->type === 'structure') {
            foreach ($element->getAncestors()->all() as $ancestor) {
                if ($ancestorUrl = $ancestor->getUrl()) {
                    $crumbs[] = ['name' => (string)$ancestor->title, 'url' => $ancestorUrl];
                }
            }
        }

        $crumbs[] = ['name' => (string)$element->title, 'url' => $url];

        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $url . '#breadcrumbs',
            'itemListElement' => $items,
        ];
    }

    private function siteUrl(Site $site): string
    {
        return rtrim($site->getBaseUrl() ?? UrlHelper::siteUrl('', null, null, $site->id), '/') . '/';
    }
}
