---
title: Upgrading to Craft 6
slug: upgrading
order: 45
summary: Moving a Craft 5 site with Schemy onto Craft 6 and Schemy 6 (beta).
---

Schemy 6 is a native Craft 6 plugin for Craft 6 and PHP 8.5. Craft 6 and Schemy 6 are in beta:
upgrade a copy of the site first.

## Before you start

Update to **Schemy 5.2.0** on Craft 5 and run its migrations. Schemy 6 won't install over an
older version.

## Upgrade

1. Upgrade Craft to 6, following Craft's own upgrade guide.
2. Require Schemy 6 and run the migrations:

   ```sh
   composer require justinholtweb/craft-schemy:@beta
   php artisan craft:up
   ```

`craft:up` runs Schemy's upgrade migration, which renames the Schema field type to its Craft 6
class. Run it in development and commit the project config change, as with the rest of the Craft
upgrade.

## What carries over

- Every setting: section and entry type schema and site identity (in Schemy's table), and the
  output switches and sidebar label and icon (in project config).
- Schemy's permissions.
- Every entry's Schema field value.
- The JSON-LD itself. Each page outputs the same graph as before.
- `craft.schemy.render()`, `add()`, `setElement()`, `disable()` and `graph()` in Twig.
- GraphQL: the `schemy` query, the field's types and the Schemy schema component.
- MCP: the same tool names and inputs.

## What changes

| Craft 5 | Craft 6 |
| --- | --- |
| `config/schemy.php` | `config/craft/schemy.php`, same keys |
| **Schemy → Settings** | **Settings → Plugins → Schemy** (the old URL redirects) |
| `php craft schemy/validate`, exit code 65 on errors | `php artisan schemy:validate`, exit code 1 on errors. `php craft schemy/validate` still works |
| The field's **Preview and validate** button | A **Schema** row in the entry sidebar, linking to the validator |
| The validator picks an entry | The validator takes an entry's ID, URI or URL |
| `Event::on(Schema::class, Schema::EVENT_BEFORE_RENDER_GRAPH, …)` with `GraphEvent` | `Event::listen(fn (GraphResolving $event) => …)` |
| `justinholtweb\schemy\…` classes | `JustinHoltWeb\Schemy\…` |
| `Plugin::getInstance()->schema` and `->validator` | `app(Schema::class)` and `app(Validator::class)` |
| Per-entry Twig, when allowed, runs unrestricted | It runs in Twig's sandbox |
| Twig only | Twig and Blade: `$craft->schemy()->render($entry)`, and `@craftHead` in Blade layouts for automatic output |
