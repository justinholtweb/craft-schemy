# Release Notes for Schemy

## 6.0.0-beta.1 - 2026-11-25

The Craft 6 line: a native port with no `craftcms/yii2-adapter`. Requires Craft 6.0.0-beta.1 and PHP 8.5. See the
[upgrade guide](https://github.com/justinholtweb/craft-schemy/blob/6.0.0/UPGRADING.md).

### Added
- Blade support: `$craft->schemy()` mirrors `craft.schemy`, and automatic output works in Blade layouts with `@craftHead`.
- A **Schema** row in the entry sidebar with the main type and a validation summary, linking to the validator.
- The validator takes an entry's ID, URI or full URL.
- Authenticated GraphQL schemas are supported; the `schemy` query returns only entries the schema's user can view.

### Changed
- Everything from 5.1.0 carries over: validator, GraphQL, MCP tools, and the sidebar label and icon.
- The Schema field's editor and the Sections and Site Identity screens are built from Craft 6's own controls.
- General settings moved to **Settings → Plugins → Schemy**.
- `GraphEvent` / `Schema::EVENT_BEFORE_RENDER_GRAPH` is now the `GraphResolving` event, dispatched with Laravel's events.
- The console command is `php artisan schemy:validate`; `schemy/validate` remains as an alias. It exits 1 on errors.
- Per-entry Twig, when a field allows it, renders in Twig's sandbox.
- Namespace `justinholtweb\schemy` → `JustinHoltWeb\Schemy`. An upgrade migration renames the stored field type.

## 5.1.0 - 2026-11-25

### Added
- A schema validator, offline, that checks structure, likely typos in type and property names,
  ISO 8601 dates, absolute URLs, `@id` use, and the properties Google's rich results require or
  recommend. It's at **Utilities → Schema Validator**, under the Schema field's preview, in
  `php craft schemy/validate` (non-zero exit on errors) and in the `validator` service.
- GraphQL: a `schemy` query returns an entry's JSON-LD by ID or URI, or the site-wide nodes, and
  respects the schema's sections and sites. The Schema field returns its settings and the rendered
  `jsonLd`, and can be saved through mutations.
- Tools for Craft's MCP server: read and validate an entry's JSON-LD, list types and rich result
  rules, and, for admins, read and change section schema, site identity and settings.
- The Schema field describes its value to Craft's MCP entry tools.
- **Sidebar label**, **Sidebar icon** and **Custom SVG icon** settings to rename Schemy and change
  its icon in the control panel. Pasted SVG is sanitized every time it's drawn.

### Changed
- Schemy now requires Craft 5.12.1 or later.
- The field's **Preview JSON-LD** button is now **Preview and validate**.

### Fixed
- A settings save that failed validation no longer leaves the rejected values in place for the
  rest of the request.

## 5.0.0

Initial release.

- Schema.org JSON-LD for sections, including singles, channels and structures: pick a type and map
  each property to a field or a short Twig template.
- Entry type overrides within a section, since entry types are shared across sections in Craft 5.
- A Schema field for individual entries, with Inherit, Customize and Disable modes, additional
  JSON-LD nodes, and a preview of the output.
- Values shaped for the property they fill: ISO 8601 dates, `ImageObject`s from image assets,
  `Person`s from users, linked nodes from related entries, plain text from rich text.
- Dot paths for nested values (`address.streetAddress`) and repeated properties for lists.
- One linked `@graph` per page: site identity, `WebSite`, the entry and a `BreadcrumbList`, joined
  by `@id`.
- Autocomplete and suggested properties for common types, and any other type typed by hand.
- Automatic output in `<head>`, or `craft.schemy.render()`, `add()`, `setElement()` and `disable()`
  from templates, and a `beforeRenderGraph` event for plugins and modules.
- `</script>` in content can't close the tag, and a template value that fails to render is logged
  and skipped rather than breaking the page.
- Settings stored in project config. No database tables, no outbound requests.
