<?php

declare(strict_types=1);

namespace justinholtweb\schemy\mcp;

use craft\elements\Entry;
use craft\mcp\attributes\RequiresAdmin;
use craft\mcp\attributes\RequiresAdminChanges;
use craft\mcp\Authorization;
use craft\mcp\McpActor;
use craft\models\EntryType;
use craft\models\Section;
use craft\services\Elements;
use craft\services\Entries;
use justinholtweb\schemy\helpers\RichResults;
use justinholtweb\schemy\helpers\SchemaTypes;
use justinholtweb\schemy\Plugin;
use justinholtweb\schemy\services\Schema;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema as JsonSchema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Schemy's tools for Craft's MCP server (Craft 5.12.1+).
 *
 * Reading an entry's JSON-LD needs permission to view the entry. Reading
 * config needs an admin, and changing it also needs `allowAdminChanges`,
 * the same as the control panel screens.
 */
class SchemyTools
{
    private const PropertyRowSchema = [
        'type' => 'object',
        'properties' => [
            'property' => ['type' => 'string', 'description' => 'schema.org property. Dot paths (address.streetAddress) and @-keys (address.@type) are allowed.'],
            'source' => ['type' => 'string', 'enum' => ['field', 'template'], 'description' => '`field` reads a field handle or one of title, url, slug, postDate, dateCreated, dateUpdated, expiryDate, author. `template` renders a Twig object template such as {title}, or plain text.'],
            'value' => ['type' => 'string'],
        ],
        'required' => ['property', 'source', 'value'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private McpActor $actor,
        private Elements $elements,
        private Entries $entries,
    ) {
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_types_list',
        description: 'Lists the schema.org types Schemy knows, with each type’s parent, properties and the kind of value each property expects (text, url, date, image, person, org, place), plus the properties Google’s rich results require or recommend per type. Other schema.org type names are accepted too; these are just the ones Schemy can suggest and check.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function typesList(): array
    {
        $types = [];

        foreach (SchemaTypes::names() as $name) {
            $types[$name] = [
                'parent' => SchemaTypes::parent($name),
                'properties' => SchemaTypes::properties($name),
            ];
        }

        return [
            'types' => $types,
            'richResults' => RichResults::all(),
        ];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_settings_get',
        description: 'Gets Schemy’s general settings and site identity: output switches, the identity type and its property rows, and the sidebar label and icon (navLabel, navIcon, navIconSvg).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresAdmin]
    public function settingsGet(): array
    {
        $settings = Plugin::getInstance()->getSettings()->toArray();
        unset($settings['sections']);

        return ['settings' => $settings];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_sections_list',
        description: 'Lists every section with its Schemy schema (type and property rows) and each entry type’s override, if any. Entry types without an override inherit the section’s schema.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresAdmin]
    public function sectionsList(): array
    {
        $schema = Plugin::getInstance()->schema;

        return [
            'sections' => array_map(fn(Section $section) => $this->serializeSection($section, $schema), $this->entries->getAllSections()),
        ];
    }

    /**
     * @param list<array{property: string, source: string, value: string}> $properties
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'schemy_section_schema_set',
        description: 'Sets the schema for a section, or for one entry type within it when entryTypeHandle is given. Replaces the type and property rows at that level; other entry type overrides are kept. An empty type clears the section’s schema, or removes the entry type’s override. Read schemy_sections_list first and pass back any rows you want to keep.',
        annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true),
    )]
    #[RequiresAdminChanges]
    public function sectionSchemaSet(
        string $type,
        #[JsonSchema(items: self::PropertyRowSchema)]
        array $properties = [],
        ?int $sectionId = null,
        ?string $sectionHandle = null,
        ?string $entryTypeHandle = null,
    ): array {
        $section = $this->findSection($sectionId, $sectionHandle);
        $schema = Plugin::getInstance()->schema;
        $cleanType = SchemaTypes::cleanName($type);

        if ($type !== '' && $cleanType === '') {
            throw new ToolCallException("`$type` isn’t a valid schema.org type name.");
        }

        $config = $schema->getSectionConfig($section);

        if ($entryTypeHandle !== null) {
            $entryType = $this->findEntryType($section, $entryTypeHandle);

            if ($cleanType === '') {
                unset($config['entryTypes'][$entryType->uid]);
            } else {
                $config['entryTypes'][$entryType->uid] = [
                    'override' => true,
                    'type' => $cleanType,
                    'properties' => $properties,
                ];
            }
        } else {
            $config['type'] = $cleanType;
            $config['properties'] = $properties;
        }

        if (!$schema->saveSectionConfig($section, $config)) {
            throw new ToolCallException('Couldn’t save the section’s schema.');
        }

        return ['section' => $this->serializeSection($section, $schema)];
    }

    /**
     * @param list<array{property: string, source: string, value: string}> $properties
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'schemy_identity_set',
        description: 'Sets the site identity node output on every page and used as the publisher of creative works. Every row is rendered as a template per site, so {{ siteName }} and {{ siteUrl }} work; the source is ignored. Replaces all identity rows.',
        annotations: new ToolAnnotations(destructiveHint: true, idempotentHint: true),
    )]
    #[RequiresAdminChanges]
    public function identitySet(
        #[JsonSchema(items: self::PropertyRowSchema)]
        array $properties,
        string $type = 'Organization',
    ): array {
        $cleanType = SchemaTypes::cleanName($type);

        if ($cleanType === '') {
            throw new ToolCallException("`$type` isn’t a valid schema.org type name.");
        }

        $properties = array_map(fn(array $row) => ['source' => 'template'] + $row, $properties);

        if (!Plugin::getInstance()->schema->saveSettings(['identityType' => $cleanType, 'identity' => $properties])) {
            throw new ToolCallException('Couldn’t save the site identity.');
        }

        return $this->settingsGet();
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_settings_set',
        description: 'Changes Schemy’s general settings. Only the arguments you pass are changed. navLabel, navIcon and navIconSvg customize the control panel sidebar; pass an empty string to restore the default. navIcon is a Craft control panel icon name such as sitemap or code. navIconSvg is one <svg> element, sanitized when drawn, and wins over navIcon.',
        annotations: new ToolAnnotations(idempotentHint: true),
    )]
    #[RequiresAdminChanges]
    public function settingsSet(
        ?bool $autoInject = null,
        ?bool $includeIdentity = null,
        ?bool $includeWebSite = null,
        ?bool $includeBreadcrumbs = null,
        #[JsonSchema(maxLength: 64)]
        ?string $navLabel = null,
        #[JsonSchema(pattern: '^([a-z0-9]+(-[a-z0-9]+)*)?$')]
        ?string $navIcon = null,
        ?string $navIconSvg = null,
    ): array {
        $changes = array_filter(compact(
            'autoInject', 'includeIdentity', 'includeWebSite', 'includeBreadcrumbs',
        ), fn($value) => $value !== null);

        // Empty strings restore the defaults
        foreach (compact('navLabel', 'navIcon', 'navIconSvg') as $key => $value) {
            if ($value !== null) {
                $changes[$key] = trim($value) === '' ? null : $value;
            }
        }

        if ($changes !== [] && !Plugin::getInstance()->schema->saveSettings($changes)) {
            $errors = Plugin::getInstance()->getSettings()->getFirstErrors();
            throw new ToolCallException('Couldn’t save settings: ' . implode(' ', $errors));
        }

        return $this->settingsGet();
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_entry_jsonld_get',
        description: 'Gets the JSON-LD document Schemy outputs for an entry, rendered as the entry’s site would render it, along with validation issues (errors, warnings and notices). Reflects the saved entry, not unsaved edits.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function entryJsonLdGet(int $entryId, ?int $siteId = null, bool $validate = true): array
    {
        $entry = $this->elements->getElementById($entryId, Entry::class, $siteId, ['status' => null]);

        if (!$entry instanceof Entry || !Authorization::allows($this->actor->user(), 'view', $entry)) {
            throw new ToolCallException('Entry not found.');
        }

        $plugin = Plugin::getInstance();
        $document = $plugin->schema->getDocumentForElement($entry);

        return [
            'entryId' => $entry->id,
            'siteId' => $entry->siteId,
            'url' => $entry->getUrl(),
            'jsonLd' => $document,
            'validation' => $validate && $document !== null ? $plugin->validator->validateDocument($document)->toArray() : null,
        ];
    }

    /** @return array<string, mixed> */
    #[McpTool(
        name: 'schemy_jsonld_validate',
        description: 'Validates a JSON-LD document offline: structure, @context, @type and @id use, likely typos in type and property names, ISO 8601 dates, absolute URLs, and properties Google’s rich results require or recommend. Returns issues with a severity (error, warning, notice) and a path. It approximates Google’s rules; the Rich Results Test is authoritative.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function jsonLdValidate(string $json): array
    {
        return Plugin::getInstance()->validator->validateJson($json)->toArray();
    }

    // Helpers
    // -------------------------------------------------------------------------

    private function findSection(?int $id, ?string $handle): Section
    {
        if (($id === null) === ($handle === null)) {
            throw new ToolCallException('Provide exactly one of: sectionId, sectionHandle.');
        }

        $section = $id !== null ? $this->entries->getSectionById($id) : $this->entries->getSectionByHandle($handle);

        if ($section === null) {
            throw new ToolCallException('Section not found.');
        }

        return $section;
    }

    private function findEntryType(Section $section, string $handle): EntryType
    {
        foreach ($section->getEntryTypes() as $entryType) {
            if ($entryType->handle === $handle) {
                return $entryType;
            }
        }

        throw new ToolCallException("The section doesn’t use an entry type with the handle `$handle`.");
    }

    /** @return array<string, mixed> */
    private function serializeSection(Section $section, Schema $schema): array
    {
        $config = $schema->getSectionConfig($section);

        return [
            'id' => $section->id,
            'uid' => $section->uid,
            'handle' => $section->handle,
            'name' => $section->name,
            'sectionType' => $section->type,
            'schemaType' => $config['type'] ?: null,
            'properties' => $config['properties'],
            'entryTypes' => array_map(fn(EntryType $entryType) => [
                'handle' => $entryType->handle,
                'name' => $entryType->name,
                'override' => isset($config['entryTypes'][$entryType->uid]),
                'schemaType' => $config['entryTypes'][$entryType->uid]['type'] ?? null,
                'properties' => $config['entryTypes'][$entryType->uid]['properties'] ?? [],
            ], $section->getEntryTypes()),
        ];
    }
}
