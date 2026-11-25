<?php

declare(strict_types=1);

namespace JustinHoltWeb\Schemy\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use JustinHoltWeb\Schemy\Helpers\RichResults;
use JustinHoltWeb\Schemy\Helpers\SchemaTypes;
use JustinHoltWeb\Schemy\Models\ValidationResult;

use function CraftCms\Cms\t;

/**
 * Checks JSON-LD for structural problems, likely typos, badly shaped values and
 * properties that rich results need. Runs entirely offline.
 *
 * Severity:
 * - error: the markup is broken, or a rich result can't be shown
 * - warning: probably a mistake, or a recommended property is missing
 * - notice: worth a look, but often fine
 */
class Validator
{
    /**
     * Value types that commonly appear nested inside catalog types. They aren't
     * in the catalog, but they shouldn't be reported as unknown.
     */
    public const array SUPPORTING_TYPES = [
        'AdministrativeArea', 'AggregateOffer', 'AggregateRating', 'Answer', 'Audience', 'Brand', 'BreadcrumbList', 'ContactPoint',
        'Country', 'CourseInstance', 'DefinedRegion', 'EducationalOccupationalCredential', 'EntryPoint',
        'GeoCoordinates', 'HowToDirection', 'HowToSection', 'HowToStep', 'HowToSupply', 'HowToTool',
        'ImageObject', 'ItemList', 'ListItem', 'MonetaryAmount', 'NutritionInformation', 'Offer',
        'OfferShippingDetails', 'OpeningHoursSpecification', 'PostalAddress', 'PriceSpecification',
        'PropertyValue', 'QuantitativeValue', 'Question', 'Rating', 'Schedule', 'SearchAction',
        'SpeakableSpecification', 'Thing', 'UnitPriceSpecification', 'VirtualLocation', 'WebPageElement',
    ];

    private const int MAX_DEPTH = 8;

    private const string ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})(T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$/';

    /** @var array<string, string[]> `@id` => types, for nodes that declare a type */
    private array $ids = [];

    /** @var array<array{id: string, path: string}> Nodes that are only an `@id` reference */
    private array $references = [];

    /**
     * Validates a JSON string.
     */
    public function validateJson(string $json): ValidationResult
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $result = new ValidationResult;
            $result->add(ValidationResult::ERROR, '$', t('This isn’t valid JSON: {error}.', [
                'error' => json_last_error() === JSON_ERROR_NONE ? t('expected an object or array', category: 'schemy') : json_last_error_msg(),
            ], category: 'schemy'));

            return $result;
        }

        return $this->validateDocument($decoded);
    }

    /**
     * Validates the document Schemy would output for an element.
     */
    public function validateElement(ElementInterface $element): ValidationResult
    {
        $document = app(Schema::class)->getDocumentForElement($element);

        if ($document === null) {
            $result = new ValidationResult;
            $result->add(ValidationResult::NOTICE, '$', t('Schemy outputs nothing for this element.', category: 'schemy'));

            return $result;
        }

        return $this->validateDocument($document);
    }

    /**
     * Validates a decoded JSON-LD document: a single node, a `@graph` document, or a list of either.
     */
    public function validateDocument(array $document): ValidationResult
    {
        $result = new ValidationResult($document);
        $this->ids = [];
        $this->references = [];

        $documents = array_is_list($document) ? $document : [$document];

        if ($documents === []) {
            $result->add(ValidationResult::ERROR, '$', t('The document is empty.', category: 'schemy'));

            return $result;
        }

        foreach ($documents as $i => $doc) {
            $path = array_is_list($document) ? "[$i]" : '$';

            if (! is_array($doc) || array_is_list($doc)) {
                $result->add(ValidationResult::ERROR, $path, t('Expected a JSON object.', category: 'schemy'));

                continue;
            }

            $this->checkContext($doc, $path, $result);

            foreach ($this->topLevelNodes($doc, $path, $result) as [$nodePath, $node]) {
                $this->collectIds($node);
                $this->checkNode($node, $nodePath, true, 0, $result);
            }
        }

        $this->checkIds($result);

        return $result;
    }

    // Document
    // -------------------------------------------------------------------------

    private function checkContext(array $doc, string $path, ValidationResult $result): void
    {
        if (! array_key_exists('@context', $doc)) {
            $result->add(ValidationResult::ERROR, $path, t('Missing `@context`. Use `"https://schema.org"`.', category: 'schemy'));

            return;
        }

        $context = $doc['@context'];
        $flat = is_string($context) ? $context : (json_encode($context) ?: '');

        if (! preg_match('#(https?:)?//schema\.org#i', $flat)) {
            $result->add(ValidationResult::WARNING, "$path.@context", t('`@context` doesn’t point to schema.org.', category: 'schemy'));
        } elseif (is_string($context) && str_starts_with(strtolower($context), 'http:')) {
            $result->add(ValidationResult::NOTICE, "$path.@context", t('Prefer `https://schema.org` over `http://`.', category: 'schemy'));
        }
    }

    /**
     * @return array<array{0: string, 1: array}>
     */
    private function topLevelNodes(array $doc, string $path, ValidationResult $result): array
    {
        if (! array_key_exists('@graph', $doc)) {
            return [[$path, $doc]];
        }

        $graph = $doc['@graph'];

        if (! is_array($graph) || ! array_is_list($graph)) {
            $result->add(ValidationResult::ERROR, "$path.@graph", t('`@graph` must be an array of nodes.', category: 'schemy'));

            return [];
        }

        $nodes = [];
        $prefix = $path === '$' ? '' : "$path.";

        foreach ($graph as $i => $node) {
            if (! is_array($node) || array_is_list($node)) {
                $result->add(ValidationResult::ERROR, "{$prefix}@graph[$i]", t('Expected a JSON object.', category: 'schemy'));

                continue;
            }
            $nodes[] = ["{$prefix}@graph[$i]", $node];
        }

        if ($nodes === []) {
            $result->add(ValidationResult::WARNING, "{$prefix}@graph", t('`@graph` is empty.', category: 'schemy'));
        }

        return $nodes;
    }

    // Nodes
    // -------------------------------------------------------------------------

    private function checkNode(array $node, string $path, bool $topLevel, int $depth, ValidationResult $result): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $types = $this->types($node);

        // A bare {"@id": "…"} is a reference to another node
        if ($types === [] && isset($node['@id']) && count(array_diff(array_keys($node), ['@id'])) === 0) {
            if (is_string($node['@id'])) {
                $this->references[] = ['id' => $node['@id'], 'path' => $path];
            }
            if ($topLevel) {
                $result->add(ValidationResult::WARNING, $path, t('This node only references `{id}` and adds nothing.', ['id' => (string) $node['@id']], category: 'schemy'));
            }

            return;
        }

        if ($types === []) {
            if ($topLevel) {
                $result->add(ValidationResult::ERROR, $path, t('Node has no `@type`.', category: 'schemy'));
            }
        } elseif (array_key_exists('@type', $node) && ! is_string($node['@type']) && ! array_is_list((array) $node['@type'])) {
            $result->add(ValidationResult::ERROR, "$path.@type", t('`@type` must be a string or a list of strings.', category: 'schemy'));
        }

        $this->checkId($node, $path, $result);

        $known = [];
        foreach ($types as $type) {
            if (SchemaTypes::exists($type)) {
                $known += SchemaTypes::properties($type);
            } elseif (! in_array($type, self::SUPPORTING_TYPES, true)) {
                $suggestion = SchemaTypes::closest($type, [...SchemaTypes::names(), ...self::SUPPORTING_TYPES]);
                if ($suggestion !== null) {
                    $result->add(ValidationResult::WARNING, "$path.@type", t('Unknown type `{type}`. Did you mean `{suggestion}`?', ['type' => $type, 'suggestion' => $suggestion], category: 'schemy'));
                    // Check the values as the type it was probably meant to be
                    if (SchemaTypes::exists($suggestion)) {
                        $known += SchemaTypes::properties($suggestion);
                    }
                } else {
                    $result->add(ValidationResult::NOTICE, "$path.@type", t('`{type}` isn’t in Schemy’s catalog, so its properties weren’t checked. Make sure it’s a schema.org type.', ['type' => $type], category: 'schemy'));
                }
            }
        }

        foreach ($node as $property => $value) {
            $property = (string) $property;

            if (str_starts_with($property, '@')) {
                continue;
            }

            $propertyPath = "$path.$property";

            if ($known !== [] && ! isset($known[$property])) {
                $suggestion = SchemaTypes::closest($property, array_keys($known));
                if ($suggestion !== null) {
                    $result->add(ValidationResult::WARNING, $propertyPath, t('Unknown property `{property}`. Did you mean `{suggestion}`?', ['property' => $property, 'suggestion' => $suggestion], category: 'schemy'));
                }
            }

            $this->checkValue($value, $known[$property] ?? null, $propertyPath, $depth, $result);
        }

        $this->checkRichResults($node, $types, $path, $topLevel, $result);
    }

    private function checkValue(mixed $value, ?string $kind, string $path, int $depth, ValidationResult $result): void
    {
        if ($value === null || $value === '' || $value === []) {
            $result->add(ValidationResult::WARNING, $path, t('The value is empty. Leave the property out instead.', category: 'schemy'));

            return;
        }

        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $i => $item) {
                $this->checkValue($item, $kind, "{$path}[$i]", $depth, $result);
            }

            return;
        }

        if (is_array($value)) {
            if ($kind === 'image' && ! isset($value['url']) && ! isset($value['contentUrl']) && ! isset($value['@id'])) {
                $result->add(ValidationResult::WARNING, $path, t('An image object needs a `url`.', category: 'schemy'));
            }
            $this->checkNode($value, $path, false, $depth + 1, $result);

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (preg_match('/<\/?(p|div|span|br|a|b|i|u|strong|em|ul|ol|li|h[1-6]|img|table|figure)\b[^>]*>/i', $value)) {
            $result->add(ValidationResult::WARNING, $path, t('The value contains HTML. Most consumers expect plain text.', category: 'schemy'));
        }

        switch ($kind) {
            case 'date':
                if (! $this->isIsoDate($value)) {
                    $result->add(ValidationResult::ERROR, $path, t('`{value}` isn’t an ISO 8601 date, like `2026-05-01` or `2026-05-01T09:00:00+00:00`.', ['value' => $this->truncate($value)], category: 'schemy'));
                }
                break;
            case 'url':
            case 'image':
                if (! preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $value)) {
                    $result->add(ValidationResult::WARNING, $path, t('`{value}` isn’t an absolute URL.', ['value' => $this->truncate($value)], category: 'schemy'));
                }
                break;
            case 'person':
            case 'org':
            case 'place':
                $result->add(ValidationResult::NOTICE, $path, t('A plain string here is accepted, but an object with `@type` and `name` is clearer to search engines.', category: 'schemy'));
                break;
        }
    }

    /**
     * @param  string[]  $types
     */
    private function checkRichResults(array $node, array $types, string $path, bool $topLevel, ValidationResult $result): void
    {
        foreach ($types as $type) {
            $rule = RichResults::rule($type, ! $topLevel);

            if ($rule === null) {
                continue;
            }

            foreach ($rule['required'] as $requirement) {
                if (! $this->satisfies($node, $requirement)) {
                    $result->add(ValidationResult::ERROR, $path, t('{type} rich results need `{property}`.', [
                        'type' => $rule['type'],
                        'property' => str_replace('|', '` or `', $requirement),
                    ], category: 'schemy'));
                }
            }

            $missing = array_filter($rule['recommended'], fn (string $recommendation) => ! $this->satisfies($node, $recommendation));

            if ($missing !== []) {
                $result->add(ValidationResult::WARNING, $path, t('Recommended for {type}: {properties}.', [
                    'type' => $rule['type'],
                    'properties' => implode(', ', array_map(fn (string $m) => '`'.str_replace('|', '` or `', $m).'`', $missing)),
                ], category: 'schemy'));
            }
        }
    }

    // IDs
    // -------------------------------------------------------------------------

    private function checkId(array $node, string $path, ValidationResult $result): void
    {
        if (! array_key_exists('@id', $node)) {
            return;
        }

        $id = $node['@id'];

        if (! is_string($id) || $id === '') {
            $result->add(ValidationResult::ERROR, "$path.@id", t('`@id` must be a non-empty string.', category: 'schemy'));
        } elseif (! preg_match('#^([a-z][a-z0-9+.-]*:|_:)#i', $id)) {
            $result->add(ValidationResult::NOTICE, "$path.@id", t('`@id` is relative. An absolute URL such as `https://example.com/page#article` is safer.', category: 'schemy'));
        }
    }

    /**
     * Records every typed node's `@id` in a top-level node, including nested ones, so references can be resolved.
     */
    private function collectIds(array $node, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $types = $this->types($node);

        if ($types !== [] && isset($node['@id']) && is_string($node['@id'])) {
            $this->ids[$node['@id']] = array_values(array_unique([...($this->ids[$node['@id']] ?? []), ...$types]));
        }

        foreach ($node as $key => $value) {
            if (! is_array($value) || str_starts_with((string) $key, '@')) {
                continue;
            }
            foreach (array_is_list($value) ? $value : [$value] as $item) {
                if (is_array($item) && ! array_is_list($item)) {
                    $this->collectIds($item, $depth + 1);
                }
            }
        }
    }

    private function checkIds(ValidationResult $result): void
    {
        foreach ($this->ids as $id => $types) {
            $specific = array_filter($types, fn (string $type) => ! $this->isAncestorOfAny($type, $types));
            if (count($specific) > 1) {
                $result->add(ValidationResult::WARNING, '$', t('`@id` `{id}` is used by nodes of different types ({types}). Each node needs its own `@id`.', [
                    'id' => $id,
                    'types' => implode(', ', $specific),
                ], category: 'schemy'));
            }
        }

        foreach ($this->references as $reference) {
            if (! isset($this->ids[$reference['id']])) {
                $result->add(ValidationResult::NOTICE, $reference['path'], t('References `{id}`, which isn’t defined in this graph.', ['id' => $reference['id']], category: 'schemy'));
            }
        }
    }

    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return string[]
     */
    private function types(array $node): array
    {
        $type = $node['@type'] ?? null;

        if (is_string($type)) {
            return $type === '' ? [] : [$type];
        }

        if (is_array($type)) {
            return array_values(array_filter($type, fn ($t) => is_string($t) && $t !== ''));
        }

        return [];
    }

    private function satisfies(array $node, string $requirement): bool
    {
        foreach (explode('|', $requirement) as $property) {
            $value = $node[$property] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private function isIsoDate(string $value): bool
    {
        if (! preg_match(self::ISO_DATE, $value, $match)) {
            return false;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]);
    }

    /**
     * @param  string[]  $types
     */
    private function isAncestorOfAny(string $type, array $types): bool
    {
        foreach ($types as $other) {
            if ($other !== $type && SchemaTypes::isA($other, $type)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57).'…' : $value;
    }
}
