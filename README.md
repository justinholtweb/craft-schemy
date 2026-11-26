# Schemy

Schema.org JSON-LD for Craft CMS 6. You set schema on sections (including singles), entry types, and individual entries, and Schemy outputs it on the front end. It doesn't manage meta tags, sitemaps, redirects, or anything else SEO-related.

This is the Craft 6 line. Schemy 5.x supports Craft 5; upgrading from it is covered in [UPGRADING.md](https://github.com/justinholtweb/craft-schemy/blob/6.0.0/UPGRADING.md).

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5+

## Installation

```bash
composer require justinholtweb/craft-schemy:^6.0.0-beta.1
php artisan craft:plugin:install schemy
```

## How it works

Every front-end page gets a single JSON-LD `@graph` containing:

1. **Site identity**: an `Organization`, `Person`, `LocalBusiness`, or similar type, configured under **Schemy → Site Identity**.
2. **WebSite**: built from the current site.
3. **The page’s main entity**: whatever schema applies to the matched entry.
4. **BreadcrumbList**: the home page, the entry’s ancestors (in structure sections), and the entry itself.
5. Anything you add from templates, from the per-entry “Additional JSON-LD” box, or from a `GraphResolving` listener.

Nodes are linked by `@id`. An Article’s `publisher`, for example, points at the identity node.

### Where an entry’s schema comes from

From most specific to least specific:

| Level | Where | Notes |
| --- | --- | --- |
| Entry | The **Schema (Schemy)** field, set to *Customize* or *Disable* | Same type as inherited: properties are layered on top. Different type: starts fresh. |
| Entry type | **Schemy → Sections → (section) → Entry type overrides** | Scoped to that section, since entry types are shared across sections. |
| Section | **Schemy → Sections → (section)** | Works the same for singles, channels, and structures. |

### Property mappings

A mapping is a property name plus a value. The value comes from one of two sources:

- **Field**: a custom field from the layout, or a built-in attribute (`title`, `url`, `postDate`, `dateUpdated`, `author`, …). Schemy converts the value based on what the property expects:
  - Dates become ISO 8601 strings.
  - Image assets on image properties become `ImageObject`s. Elsewhere, assets become their URL.
  - Users become `Person`s.
  - Related entries become their titles, or `Person`/`Organization`/`Place` nodes where the property calls for one.
  - Rich text is stripped to plain text.
  - Option fields output their labels.
- **Template**: a Twig object template rendered against the entry, e.g. `{title}`, `{{ entry.summary }}`, or `{{ entry.postDate|date('Y') }}`. Plain text works too.

Some extras:

- **Nested values** use dot paths: `address.@type` → `PostalAddress`, `address.streetAddress` → `{street}`.
- **Lists** come from repeating a property: three `sameAs` rows output an array of three URLs.
- **Arbitrary JSON**: if a template’s output starts with `{` or `[` and is valid JSON, it’s inserted as structured data:

  ```twig
  {{ entry.faqs.all()|map(f => {'@type': 'Question', name: f.question, acceptedAnswer: {'@type': 'Answer', text: f.answer|striptags}})|json_encode }}
  ```

The type and property inputs autocomplete from a curated list of common schema.org types. You can still type any type or property name.

### Per-entry field

Add the **Schema (Schemy)** field to any field layout. It has three modes:

- **Inherit**: use the entry type or section schema. The field shows which one applies.
- **Customize**: pick a type and add or override properties.
- **Disable**: output no main entity for this entry. Site-wide nodes still appear.

It also has an *Additional JSON-LD* box for raw nodes. The entry's sidebar shows a **Schema** row with the main type and a validation summary for the saved entry, linking to the validator.

By default, template values entered on entries are output as plain text and never run as Twig, so authors can’t run Twig. Turn on **Parse Twig in values** in the field settings to render them in Twig’s sandbox, if you trust everyone who edits those entries.

The field works on any element type with a field layout (users, addresses, Commerce products, …). These elements get schema only through the field, since section defaults apply only to entries.

## Templates

With **Output automatically** on (the default), Schemy adds the script tag to `<head>` on every front-end page. Twig layouts get the head placeholder automatically; **Blade layouts need `@craftHead`** in their `<head>`.

To control placement yourself, turn it off and output it where you want:

```twig
{{ craft.schemy.render(entry ?? null) }}
```

```blade
{!! $craft->schemy()->render($entry ?? null) !!}
```

Calling `render()` also stops automatic output for that request, so you can mix the two.

Other helpers, the same in both engines (`craft.schemy.x()` in Twig, `$craft->schemy()->x()` in Blade):

```twig
{# Add a node to this page's graph #}
{% do craft.schemy.add({ '@type': 'Event', name: 'Launch party', startDate: '2026-11-01' }) %}

{# Describe a different element (e.g. on a custom route), or pass false for site-wide nodes only #}
{% do craft.schemy.setElement(product) %}

{# Output nothing on this page #}
{% do craft.schemy.disable() %}

{# Get the graph as an array #}
{% set nodes = craft.schemy.graph(entry) %}
```

## Validator

Checks JSON-LD for broken structure, likely typos, bad dates and URLs, and the properties Google’s rich results need, without any outbound requests.

- **Utilities → Schema Validator** (also **Schemy → Validator**): validate an entry by ID, URI or URL, or paste JSON-LD
- The **Schema** row in the entry sidebar
- `php artisan schemy:validate --section=news` (exits 1 on errors, for CI; `php craft schemy/validate` still works)
- `app(\JustinHoltWeb\Schemy\Services\Validator::class)->validateElement($entry)`

## GraphQL

Enable the Schemy component in a GraphQL schema, then:

```graphql
{ schemy(uri: "news/harbour-reopens") { jsonLd script } }
```

Leave out `id`/`uri` for the site-wide nodes. Only entries in the schema’s sections and sites are found, and on an authenticated schema only ones its user can view. The Schema field also returns `mode`, `type`, `properties`, `extraJson` and the rendered `jsonLd`, and accepts a `SchemyFieldInput` in mutations.

## MCP

Schemy adds tools to Craft’s MCP server: `schemy_entry_jsonld_get`, `schemy_jsonld_validate`, `schemy_types_list`, `schemy_sections_list`, `schemy_section_schema_set` and `schemy_identity_set` (for users with Schemy’s permissions), and, for admins, `schemy_settings_get` and `schemy_settings_set` (which needs `allowAdminChanges`). Writes need the token’s full-access scope. The names and input schemas match Schemy 5’s. The Schema field describes its value to Craft’s own element tools, so assistants can set per-entry schema too.

## Settings and environments

Section mappings, entry type overrides and the site identity are stored in Schemy’s own table, so they can be edited in any environment, production included. Non-admins need the **Edit section and entry type schema** permission (plus permission to view a section’s entries) or **Edit site identity**. Copy them between environments with `php artisan schemy:schemas:export --file=schemy.json` and `schemy:schemas:import`.

The output switches and sidebar settings are plugin settings in **project config**, at **Settings → Plugins → Schemy**, read-only where `allowAdminChanges` is off. You can override them in `config/craft/schemy.php`:

```php
<?php

return [
    'autoInject' => true,
    'includeBreadcrumbs' => false,
    // Rename Schemy and change its icon in the control panel sidebar
    'navLabel' => 'Structured Data',
    'navIcon' => 'sitemap',
];
```

## Events

Change the graph before it’s encoded:

```php
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Schemy\Events\GraphResolving;

Event::listen(function (GraphResolving $event) {
    $event->graph[] = ['@type' => 'SiteNavigationElement', 'name' => 'Main'];
});
```

## Troubleshooting

- **Nothing in `<head>` on a Blade page.** Blade layouts must call `@craftHead`, `@craftBeginBody` and `@craftEndBody`; Twig layouts get them automatically.
- **`Undefined property … CraftVariable::$schemy` in Blade.** It’s a method there: `$craft->schemy()`.
- **The main entity is missing.** Check **Schemy → Sections**: the section (or the entry type override) needs a type. The entry sidebar’s **Schema** row says what Schemy outputs for the saved entry.
- **A Field-source property is empty.** The value must be a field handle on the entry’s layout, or one of `title`, `url`, `slug`, `postDate`, `dateCreated`, `dateUpdated`, `expiryDate`, `author`. Plain text belongs in the Template source.
- **The settings screen is read-only.** `allowAdminChanges` is off in this environment; change settings in development and deploy project config. Section schema and the identity stay editable.
- **Section schema didn’t deploy.** It’s stored in the database, not project config: use `schemy:schemas:export` and `schemy:schemas:import`.
- **The `schemy` GraphQL query is missing.** Enable the Schemy component on the schema, and the sections and sites you want it to read.

## Development

```bash
composer install
composer test        # Pest, on in-memory SQLite
composer phpstan     # Larastan
composer check-cs    # Pint
```

## License

Schemy is free, one edition with everything included. It is released under the [Craft License](LICENSE.md).
