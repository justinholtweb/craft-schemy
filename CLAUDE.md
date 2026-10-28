# Schemy — Craft CMS 5 Plugin

## Project Overview

Schemy adds schema.org JSON-LD to Craft sites, set per section (singles included), per entry type and
per entry, without any of the rest of an SEO suite: no meta tags, no sitemap, no redirects. Distributed
as `justinholtweb/craft-schemy`. **Free, under the Craft License, with no editions and no licensing code.**

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.0+**, Yii2, Twig
- No build step, no database tables, no outbound HTTP. One classic IIFE in
  `src/web/assets/cp/dist/schemy.js`.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\schemy`
- Package: `justinholtweb/craft-schemy`
- Handle: `schemy`

### Where config lives

- **Sections and entry types**: plugin settings in project config, `sections` keyed by section UID →
  `{type, properties, entryTypes: {entryTypeUid: {override, type, properties}}}`. Entry types are
  keyed inside their section because Craft 5 shares entry types across sections.
- **Site identity and general switches**: also plugin settings.
- **Per entry**: the `SchemaField` value (`models\SchemaOverride`, a JSON column) with mode
  `inherit | override | disable`, its own type and properties, and `extraJson`.

`savePluginSettings()` writes only the keys it's given, so every controller save merges into
`getSettings()->toArray()` and passes the whole array. Pass a partial array and the other settings
are lost.

### Resolution and merge (`services\Schema`)

Entry field → entry type override → section. An entry override with the **same** type layers its
properties onto the inherited ones. A **different** type starts fresh. Disable drops only the entry's
own node; identity, WebSite, breadcrumbs and extra JSON stay. `promos/slides.html` slide 4 describes
this rule, so update it if the rule changes.

Property rows are `{property, source: field|template, value}` (`helpers\PropertyRows::normalize()`),
with dot paths for nested values and `@`-keys allowed. Field values are shaped by the property's
kind in `helpers\SchemaTypes` (date, image, person, org, place, url, text). The type catalog is
curated, about 35 types, and any other type name is still accepted.

### Output

`View::EVENT_END_PAGE` on site requests calls `injectIntoView()` (`POS_HEAD`) unless a template already
called `render()` or `disable()`. JSON is encoded with `JSON_HEX_TAG` so `</script>` in content can't
close the tag. A template value that throws is logged as a warning and skipped, so a broken template
never takes the page down. `EVENT_BEFORE_RENDER_GRAPH` lets other code edit the final graph.

The field's `allowTwig` setting defaults to **false**: per-entry template values are plain text unless
an admin opts in, because authors who can edit entries are not necessarily trusted with Twig.

## Testing

No local PHP on this Mac. Lint and PHPStan run in Docker, and integration testing is on the
plugin-testing site (`~/Sites/plugin-testing`, DDEV), where this repo is mounted at
`/var/www/craft-schemy` and required as a path repository.

```sh
docker run --rm -v "$PWD":/app -w /app php:8.2-cli bash -c 'find src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

Fixtures on plugin-testing: a `schemySchema` field on the newsArticle layout, the `schemyAbout`
single (`/schemy-about`), and templates in `templates/schemy-test/`. News and pages sit behind
another plugin's login wall for anonymous requests, so check those through the service from a console
bootstrap. A bootstrap script that writes settings has to call
`Craft::$app->getProjectConfig()->saveModifiedConfigData()`, or the writes never flush.

## Marketing site and promos

The marketing page is hosted inside justinholt.com at `/plugins/craft-schemy`. The page seed is
`~/Sites/justinholt/scripts/seed/plugin-pages/craft-schemy.json`, and the docs are synced from this
repo's `docs/`, which needs front matter. Doc cross-links must be absolute
`https://justinholt.com/plugins/craft-schemy/docs/<slug>` URLs. Plugin Store promos are in `promos/`;
see its README.

## Coding conventions

- `Craft::t('schemy', '…')` for user-facing strings; `src/translations/en/schemy.php` lists them
- Business logic in services; controllers stay thin
- CP screens are admin-only; saves also need `allowAdminChanges`, and the screens render read-only
  without it
- Never mark plugin settings `required`, because it blocks fresh installs
