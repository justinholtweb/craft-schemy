# Schemy — Craft CMS 5 Plugin

## Project Overview

Schemy adds schema.org JSON-LD to Craft sites, set per section (singles included), per entry type and
per entry, without any of the rest of an SEO suite: no meta tags, no sitemap, no redirects. Distributed
as `justinholtweb/craft-schemy`. **Free, under the Craft License, with no editions and no licensing code.**

## Branches

`6.0.0` (this branch, worktree `~/Sites/craft6-ports/craft-schemy`) is the Craft 6 line: a native port,
no `craftcms/yii2-adapter`, following the family plan in `~/Sites/plugin-testing-craft6/PLAN.md` and
`porting/COOKBOOK.md`. Craft 5 work happens on the 5.x branch in `~/Sites/craft-schemy`.

## Tech Stack

- **PHP 8.5**, **Craft CMS 6** (Laravel 13), Twig and Blade
- No build step, no database tables, no outbound HTTP. CP screens and the field editor are built from
  Craft's Ui controls; the only asset is `resources/css/cp.css`.

## Architecture

- Namespace `JustinHoltWeb\Schemy`, package `justinholtweb/craft-schemy`, handle `schemy`
- `Plugin` registers through properties (`$fieldTypes`, `$utilities`, `$gqlQueries`, `$commands`,
  `$events`, `$styles`) and `boot()` (MCP `CapabilityRegistry`, `CraftVariable::macro('schemy')`,
  the GraphQL schema component). CP routes are in `routes/cp.php`.
- `Services\Schema` is `#[Scoped]` (per-request template state); `Services\Validator` is a singleton.
  Resolve both from the container.

### Where config lives

Same as 5.2: section, entry type and identity schema in the `schemy_schemas` table through
`Services\Store` (one row per scope, `identity` or a section UID, with `type`, `properties`,
`entryTypes` and `untrusted`), so it's editable where `allowAdminChanges` is off (issue #1).
`untrusted` is set when a non-admin saves values containing `{` (`Store::isTrusted()`: a `CraftUser` contract, not the
`User` element, since `currentUser()` returns the app's model; only admins or code passing `true`
are trusted) and sticks through admin saves until an admin switches on "Run these values as Twig"
(`trustTwig`, `SectionsController::vouch()`) or no value contains `{`. Twig in untrusted rows is
never run, not even sandboxed (the sandbox still allows `entry.author.email`): `buildNode()`'s
`'untrusted'` mode fills in only `{{ siteName }}`/`{{ siteUrl }}`. Export/import carries the flag. Switches and
`navLabel`/`navIcon`/`navIconSvg` are plugin settings (project config); save them through
`Schema::saveSettings()`, which rolls a rejected save back. Per entry: the `SchemaField` value.

### Resolution and merge

Entry field → entry type override → section. Same type layers properties; a different type starts
fresh. Disable drops only the entry's node. `promos/slides.html` slide 4 describes this rule.
Property rows are `{property, source: field|template, value}`; field values are shaped by the
property kind in `Helpers\SchemaTypes`.

### Output

`Listeners\InjectJsonLd` handles `PageEnded` on site pages and pushes the script onto `HtmlStack`
(`Position::Head`) unless a template called `render()` or `disable()`. Blade layouts need
`@craftHead`. JSON uses `JSON_HEX_TAG`. A throwing template value is logged and skipped.
`Events\GraphResolving` lets other code edit the graph. Per-entry template values are plain text
unless the field's `allowTwig` is on, and then they render in Twig's sandbox.

### Field editor

`SchemaField::uiControl()` returns a `ContentBlock` control with a nested `Ui` (mode `Choice`, type
`Combobox`, `Helpers\PropertyTable`, extra JSON `Textarea`). It posts `{mode, type, properties,
extraJson}` under the field's path, which `normalizeValue()` reads directly. Proven in the harness.

## Testing

Harness: `~/Sites/plugin-testing-craft6` (DDEV, shared with other sessions; read its README and
`porting/COOKBOOK.md` harness etiquette). Run shared-state commands through `bin/harness-lock`.
`bin/port-check craft-schemy` must stay green.

- PHP 8.5 lint: `docker exec -w /var/www/ports/craft-schemy ddev-plugin-testing-craft6-web bash -c 'find src routes database -name "*.php" -print0 | xargs -0 -n1 php -l'`
- Pint: the harness's `/var/www/html/vendor/bin/pint --preset laravel src routes database lang`
- Pest + Larastan need the plugin's own `vendor/`, which Composer currently refuses to install
  because of security advisories on the `twig/twig` version Craft 6 pins. Until that's resolved
  upstream, check behaviour in the harness (tinker scripts under `storage/schemy-tmp/`, deleted after).
- MCP: `(cat requests.jsonl; sleep 6) | docker exec -i -w /var/www/html ddev-plugin-testing-craft6-web php artisan craft:mcp:serve --user=admin`
- Browser: `php artisan craft:users:impersonate admin` for a login link, and block `**/queue/run**`
  (Playwright `context.route`) so opening the CP doesn't run other sessions' queued jobs.

## Marketing site and promos

The marketing page is hosted inside justinholt.com at `/plugins/craft-schemy`. The page seed is
`~/Sites/justinholt/scripts/seed/plugin-pages/craft-schemy.json`, and the docs are synced from this
repo's `docs/`, which needs front matter. Doc cross-links must be absolute
`https://justinholt.com/plugins/craft-schemy/docs/<slug>` URLs. Plugin Store promos are in `promos/`;
see its README.

## Coding conventions

- `t('…', category: 'schemy')` for user-facing strings; `lang/en/schemy.php` lists overrides
- Business logic in services; controllers are thin `readonly` classes with injected services
- Sections and Site Identity routes need `can:schemy-editSections` / `can:schemy-editIdentity`
  (inside `can:accessPlugin-schemy`); a section also needs `viewEntries:<uid>`
  (`Plugin::canManageSection()`, used by the screens and MCP). Use `Gate`, not `$user->can()`.
  Plugin settings are admin-only and read-only without `allowAdminChanges`
- Text settings are nullable (Laravel turns blank input into null); never mark settings `required`
- Every template-facing feature works in Twig and Blade
