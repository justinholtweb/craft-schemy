<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Asset\Enums\FileKind;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Data\OptionData;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\Plugin\Plugins;
use CraftCms\Cms\Route\MatchedElement;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\User\Contracts\CraftUser;
use CraftCms\Cms\User\Elements\User;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Schemy\Events\GraphResolving;
use JustinHoltWeb\Schemy\Fields\SchemaField;
use JustinHoltWeb\Schemy\Helpers\PropertyRows;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;
use JustinHoltWeb\Schemy\Models\SchemaOverride;
use JustinHoltWeb\Schemy\Models\Settings;
use JustinHoltWeb\Schemy\Plugin;
use Stringable;
use Throwable;
use Twig\Markup;

use function CraftCms\Cms\renderObjectTemplate;
use function CraftCms\Cms\renderSandboxedObjectTemplate;
use function CraftCms\Cms\t;

/**
 * Builds the JSON-LD graph for a page.
 *
 * Scoped: one instance per request, because templates change what this request outputs
 * (`craft.schemy.add()`, `setElement()`, `disable()`).
 */
#[Scoped]
class Schema
{
    /**
     * Element attributes that can be mapped without being in the field layout.
     */
    public const array BUILT_IN_ATTRIBUTES = [
        'title' => 'Title',
        'url' => 'URL',
        'slug' => 'Slug',
        'postDate' => 'Post Date',
        'dateCreated' => 'Date Created',
        'dateUpdated' => 'Date Updated',
        'expiryDate' => 'Expiry Date',
        'author' => 'Author',
    ];

    private const int MAX_DEPTH = 4;

    /** @var list<array<mixed>> Nodes added from templates via `craft.schemy.add()` */
    private array $extraNodes = [];

    private bool $disabled = false;

    private bool $rendered = false;

    private ElementInterface|false|null $elementOverride = null;

    // Request state (driven by craft.schemy in templates)
    // -------------------------------------------------------------------------

    /**
     * @param  array<mixed>  $node
     */
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
     * The `<script>` tag automatic output should add to `<head>`, or null when a template already
     * rendered it, disabled output, or there's nothing to say. Called at the end of every
     * front-end page.
     */
    public function pendingScript(): ?string
    {
        if ($this->disabled || $this->rendered) {
            return null;
        }

        $element = $this->elementOverride ?? MatchedElement::get();
        $script = $this->renderScript($element ?: null);

        return $script === '' ? null : $script;
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
        $json = self::encode($document, true);

        return $json === '' ? '' : Html::tag('script', $json, ['type' => 'application/ld+json']);
    }

    /**
     * Returns the JSON-LD document (`@context` + `@graph`), or null if there's nothing to output.
     *
     * @return array{'@context': string, '@graph': list<array<mixed>>}|null
     */
    public function getDocument(?ElementInterface $element = null): ?array
    {
        $graph = $this->getGraph($element);

        if ($graph === []) {
            return null;
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];
    }

    /**
     * Returns the document for an element as its own site would render it, switching the current
     * site while object templates run. Use this outside of a front-end request (control panel,
     * GraphQL, MCP, console). With no element, returns the site-wide nodes for `$site`.
     *
     * @return array{'@context': string, '@graph': list<array<mixed>>}|null
     */
    public function getDocumentForElement(?ElementInterface $element, ?Site $site = null): ?array
    {
        $originalSite = Sites::getCurrentSite();
        Sites::setCurrentSite($element?->getSite() ?? $site ?? $originalSite);

        try {
            return $this->getDocument($element);
        } finally {
            Sites::setCurrentSite($originalSite);
        }
    }

    /**
     * Encodes a document as JSON. `$forHtml` escapes `<` and `>` so the result is safe inside a `<script>` tag.
     *
     * @param  array<mixed>  $document
     */
    public static function encode(array $document, bool $forHtml = false, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($forHtml) {
            $flags |= JSON_HEX_TAG;
        }

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($document, $flags) ?: '';
    }

    // Saving config
    // -------------------------------------------------------------------------

    /**
     * Saves plugin settings. Craft applies the values to the settings model before validating
     * them, so a rejected save is rolled back here: later reads and saves in this request don't
     * pick up the rejected values. The validation errors stay on the model for display.
     *
     * @param  array<string, mixed>  $changes
     */
    public function saveSettings(array $changes): bool
    {
        $plugin = Plugin::getInstance();
        $model = $plugin->getSettings();
        $previous = $model->validationData();

        if (app(Plugins::class)->savePluginSettings($plugin, $changes)) {
            return true;
        }

        $errors = $model->errors()->getMessages();
        $model->setAttributes($previous);
        $model->clearErrors();

        foreach ($errors as $attribute => $messages) {
            foreach ($messages as $message) {
                $model->errors()->add($attribute, $message);
            }
        }

        return false;
    }

    /**
     * Saves a section's schema config, replacing what was there. Section schema lives in Schemy's
     * own table, not project config, so this works where `allowAdminChanges` is off.
     *
     * Entry type configs are keyed by entry type UID. Ones without `override` are dropped, as are
     * UIDs that don't belong to the section.
     *
     * @param  array{type?: mixed, properties?: mixed, entryTypes?: mixed}  $config
     * @param  User|bool|null  $savedBy  Who vouches for the Twig; see `Store::isTrusted()`
     */
    public function saveSectionConfig(Section $section, array $config, CraftUser|bool|null $savedBy = null): bool
    {
        $validUids = array_map(fn (EntryType $entryType) => $entryType->uid, $section->getEntryTypes());
        $entryTypes = [];

        foreach ((array) ($config['entryTypes'] ?? []) as $uid => $entryTypeConfig) {
            if (! in_array($uid, $validUids, true) || ! is_array($entryTypeConfig) || empty($entryTypeConfig['override'])) {
                continue;
            }

            $entryTypes[$uid] = [
                'override' => true,
                'type' => SchemaTypes::cleanName($entryTypeConfig['type'] ?? ''),
                'properties' => PropertyRows::normalize((array) ($entryTypeConfig['properties'] ?? [])),
            ];
        }

        return $this->store()->saveSectionConfig($section->uid, [
            'type' => SchemaTypes::cleanName($config['type'] ?? ''),
            'properties' => PropertyRows::normalize((array) ($config['properties'] ?? [])),
            'entryTypes' => $entryTypes,
        ], $savedBy);
    }

    /**
     * Returns a section's stored config in a predictable shape. `untrusted` is true when it holds
     * Twig from someone who isn't an admin.
     *
     * @return array{type: string, properties: list<array{property: string, source: string, value: string}>, entryTypes: array<string, array{override: true, type: string, properties: list<array{property: string, source: string, value: string}>}>, untrusted: bool}
     */
    public function getSectionConfig(Section $section): array
    {
        return $this->store()->getSectionConfig((string) $section->uid);
    }

    /**
     * Returns the list of nodes for the page.
     *
     * @return list<array<mixed>>
     */
    public function getGraph(?ElementInterface $element = null): array
    {
        $settings = $this->settings();
        $site = $element?->getSite() ?? Sites::getCurrentSite();
        $siteUrl = $this->siteUrl($site);
        $identityId = $siteUrl.'#identity';
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
                '@id' => $siteUrl.'#website',
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
                    if ($identity && ! isset($main['publisher']) && isset(SchemaTypes::properties($main['@type'])['publisher'])) {
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

        if (Event::hasListeners(GraphResolving::class)) {
            event($event = new GraphResolving($element, $graph));
            $graph = $event->graph;
        }

        return array_values($graph);
    }

    // Config resolution
    // -------------------------------------------------------------------------

    /**
     * Returns the element's Schema field value, if its field layout has one.
     */
    public function getOverride(ElementInterface $element): ?SchemaOverride
    {
        $field = $this->findSchemaField($element->getFieldLayout());

        if ($field === null) {
            return null;
        }

        try {
            $value = $element->getFieldValue((string) $field->handle);
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
     * @return array{type: string, properties: list<array{property: string, source: string, value: string}>, untrusted: bool, from: string}|null
     */
    public function getInheritedConfig(ElementInterface $element): ?array
    {
        if (! $element instanceof Entry) {
            return null;
        }

        $section = $element->getSection();

        if ($section === null) {
            return null;
        }

        $config = $this->getSectionConfig($section);
        $entryType = $element->getType();
        $entryTypeConfig = $config['entryTypes'][$entryType->uid] ?? null;

        if ($entryTypeConfig !== null) {
            $type = trim($entryTypeConfig['type']);

            return $type === '' ? null : [
                'type' => $type,
                'properties' => $entryTypeConfig['properties'],
                'untrusted' => $config['untrusted'],
                'from' => t('{section} › {entryType}', [
                    'section' => $section->name,
                    'entryType' => $entryType->name,
                ], category: 'schemy'),
            ];
        }

        $type = trim($config['type']);

        return $type === '' ? null : [
            'type' => $type,
            'properties' => $config['properties'],
            'untrusted' => $config['untrusted'],
            'from' => (string) $section->name,
        ];
    }

    /**
     * Builds the element's primary node.
     *
     * When an entry overrides with the same type it inherits, its rows are
     * layered over the inherited rows. A different type starts fresh.
     *
     * @return array<mixed>|null
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
        // Section and entry type rows saved by someone who isn't an admin don't run Twig
        $node = $this->buildNode($type, $rows, $element, ($inherited['untrusted'] ?? false) ? 'untrusted' : true);

        // Entry-level rows replace inherited values for the same property. Authors aren't
        // necessarily trusted with Twig, so their templates run sandboxed, and only when the
        // field allows it.
        $entryNode = $this->buildNode($type, $overrideRows, $element, $allowTwig ? 'sandboxed' : false);
        unset($entryNode['@type']);
        $node = array_merge($node, $entryNode);

        if (! isset($node['@id']) && ($url = $element->getUrl())) {
            $node = ['@type' => $node['@type'], '@id' => $url.'#main'] + $node;
        }

        return $node;
    }

    /**
     * @return array<mixed>|null
     */
    public function buildIdentityNode(Site $site, string $id): ?array
    {
        $identity = $this->store()->getIdentity();
        $node = $this->buildNode($identity['type'], $identity['properties'], $site, $identity['untrusted'] ? 'untrusted' : true);

        if (count($node) <= 1) {
            return null;
        }

        return ['@type' => $node['@type'], '@id' => $id] + $node;
    }

    /**
     * Builds a node from property rows.
     *
     * @param  list<array{property: string, source: string, value: string}>  $rows
     * @param  ElementInterface|Site|null  $object  What field values and object templates are read from
     * @param  bool|'sandboxed'|'untrusted'  $parseTwig  Whether template values are rendered as Twig (`sandboxed`: in Twig's sandbox), used literally, or (`untrusted`, values saved by someone who isn't an admin) used literally with only `{{ siteName }}` and `{{ siteUrl }}` filled in
     * @return array<mixed>
     */
    public function buildNode(string $type, array $rows, ElementInterface|Site|null $object, bool|string $parseTwig = true): array
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
     * Options for the "Field" source, given the field layouts in play.
     *
     * @param  array<FieldLayout|null>  $layouts
     * @return list<array{label: string, value: string}>
     */
    public function fieldOptions(array $layouts): array
    {
        $options = [];

        foreach (self::BUILT_IN_ATTRIBUTES as $handle => $label) {
            $options[$handle] = ['label' => t($label), 'value' => $handle];
        }

        foreach ($layouts as $layout) {
            foreach ($layout?->getCustomFields() ?? [] as $field) {
                $handle = (string) $field->handle;

                if ($field instanceof SchemaField || $handle === '' || isset($options[$handle])) {
                    continue;
                }

                $options[$handle] = [
                    'label' => sprintf('%s (%s)', $field->name, $handle),
                    'value' => $handle,
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
            } elseif (isset(self::BUILT_IN_ATTRIBUTES[$handle])) {
                $raw = $this->builtInValue($element, $handle);
            } else {
                return null;
            }

            return $this->normalize($raw, $kind);
        } catch (Throwable $e) {
            Log::warning("Schemy couldn't read `$handle`: {$e->getMessage()}");

            return null;
        }
    }

    private function builtInValue(ElementInterface $element, string $handle): mixed
    {
        return match ($handle) {
            'url' => $element->getUrl(),
            'author' => $element instanceof Entry ? $element->getAuthor() : null,
            default => property_exists($element, $handle) ? $element->$handle : null,
        };
    }

    private function templateValue(string $template, ElementInterface|Site|null $object, string $kind, bool|string $parseTwig): mixed
    {
        if (trim($template) === '') {
            return null;
        }

        if ($parseTwig === 'untrusted') {
            $output = $this->untrustedValue($template, $object);
        } elseif ($parseTwig !== false) {
            $variables = [
                'entry' => $object instanceof Entry ? $object : null,
                'element' => $object instanceof ElementInterface ? $object : null,
            ];

            try {
                $output = $parseTwig === 'sandboxed'
                    ? renderSandboxedObjectTemplate($template, $object ?? new \stdClass, $variables)
                    : renderObjectTemplate($template, $object ?? new \stdClass, $variables);
            } catch (Throwable $e) {
                Log::warning("Schemy couldn't render template `$template`: {$e->getMessage()}");

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
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Asset) {
            return $this->normalizeAsset($value, $kind);
        }

        if ($value instanceof User) {
            $name = trim((string) $value->fullName);

            return $name === '' ? null : ['@type' => 'Person', 'name' => $name];
        }

        if ($value instanceof ElementInterface) {
            return $this->normalizeElement($value, $kind);
        }

        if ($value instanceof OptionData) {
            return ($value->value === null || $value->value === '') ? null : (string) $value->label;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $this->coerceString($this->cleanText($value), $kind);
        }

        if ($value instanceof Markup) {
            return $this->coerceString($this->cleanText((string) $value), $kind);
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

        if ($value instanceof Stringable) {
            return $this->coerceString($this->cleanText((string) $value), $kind);
        }

        return null;
    }

    private function normalizeAsset(Asset $asset, string $kind): mixed
    {
        $url = $asset->getUrl();

        if (! $url) {
            return null;
        }

        if ($kind !== 'image' || $asset->kind !== FileKind::Image->value) {
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
        $name = trim((string) ($element->title ?? '')) ?: (string) $element;
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
     *
     * @param  array<mixed>  $node
     */
    private function setPath(array &$node, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $last = array_pop($keys);
        $target = &$node;

        foreach ($keys as $key) {
            if (! isset($target[$key]) || ! is_array($target[$key]) || array_is_list($target[$key])) {
                $target[$key] = [];
            }
            $target = &$target[$key];
        }

        if (! array_key_exists($last, $target)) {
            $target[$last] = $value;
        } elseif (is_array($target[$last]) && array_is_list($target[$last])) {
            $target[$last][] = $value;
        } else {
            $target[$last] = [$target[$last], $value];
        }
    }

    // Breadcrumbs
    // -------------------------------------------------------------------------

    /**
     * @return array<mixed>|null
     */
    private function buildBreadcrumbs(ElementInterface $element, Site $site, string $siteUrl): ?array
    {
        $url = $element->getUrl();

        if (! $url || $element->uri === Element::HOMEPAGE_URI) {
            return null;
        }

        $crumbs = [['name' => $site->getName(), 'url' => $siteUrl]];

        if ($element instanceof Entry && $element->getSection()?->type === SectionType::Structure) {
            $ancestors = $element->getAncestors();

            foreach ($ancestors instanceof ElementQueryInterface ? $ancestors->all() : $ancestors->all() as $ancestor) {
                if ($ancestorUrl = $ancestor->getUrl()) {
                    $crumbs[] = ['name' => (string) $ancestor->title, 'url' => $ancestorUrl];
                }
            }
        }

        $crumbs[] = ['name' => (string) $element->title, 'url' => $url];

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
            '@id' => $url.'#breadcrumbs',
            'itemListElement' => $items,
        ];
    }

    /**
     * Fills in the placeholders allowed in values saved by someone who isn't an admin. Their Twig
     * isn't run: even Twig's sandbox lets a template reach related data such as an author's
     * email, which would then be published.
     */
    private function untrustedValue(string $template, ElementInterface|Site|null $object): string
    {
        $site = $object instanceof Site ? $object : ($object?->getSite() ?? Sites::getCurrentSite());

        return (string) preg_replace_callback('/\{\{\s*(siteName|siteUrl)\s*\}\}/', fn (array $match) => match ($match[1]) {
            'siteName' => (string) $site->getName(),
            default => $this->siteUrl($site),
        }, $template);
    }

    private function siteUrl(Site $site): string
    {
        return rtrim($site->getBaseUrl() ?? Url::siteUrl('', null, null, $site->id), '/').'/';
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function store(): Store
    {
        return app(Store::class);
    }
}
