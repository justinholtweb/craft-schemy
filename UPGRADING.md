# Upgrading from Schemy 5 to Schemy 6

Schemy 6 is a native Craft 6 plugin. It needs Craft 6 and PHP 8.5, and has no `craftcms/yii2-adapter` dependency.

## Before you start

Update to **Schemy 5.1.0** on Craft 5 first and run its migrations. Schemy 6 refuses to install over anything older (`$minVersionRequired`).

## Upgrade

1. Upgrade Craft to 6 following Craft's own guide.
2. Require Schemy 6:

   ```sh
   composer require justinholtweb/craft-schemy:^6.0.0-beta.1
   php artisan craft:up
   ```

`craft:up` runs Schemy's upgrade migration, which renames the Schema field type in the `fields` table and project config (`justinholtweb\schemy\fields\SchemaField` → `JustinHoltWeb\Schemy\Fields\SchemaField`). Run it in development and commit the project config change, as with any Craft upgrade.

## What carries over

- Every setting: section and entry type schema, site identity, output switches, and the sidebar label and icon. The project config keys are unchanged.
- Every entry's Schema field value.
- The JSON-LD itself. A page outputs the same graph as before.
- `craft.schemy.render()`, `add()`, `setElement()`, `disable()` and `graph()` in Twig.
- GraphQL: the `schemy` query, the field's output and input types, and the `schemy.all:read` component.
- MCP: the same tool names and input schemas.
- `config/schemy.php` overrides move to `config/craft/schemy.php`, with the same keys.

## What changed

| Schemy 5 | Schemy 6 |
| --- | --- |
| Namespace `justinholtweb\schemy\…` | `JustinHoltWeb\Schemy\…` |
| `Event::on(Schema::class, Schema::EVENT_BEFORE_RENDER_GRAPH, fn (GraphEvent $e) => …)` | `Event::listen(fn (\JustinHoltWeb\Schemy\Events\GraphResolving $e) => …)`. `$e->graph` and `$e->element` are the same |
| `Plugin::getInstance()->schema` / `->validator` | `app(\JustinHoltWeb\Schemy\Services\Schema::class)` / `app(…\Validator::class)` |
| `php craft schemy/validate` (exit 65 on errors) | `php artisan schemy:validate` (exit 1 on errors, 2 on bad usage). The old name still works through `php craft`. |
| **Schemy → Settings** | **Settings → Plugins → Schemy** (the old URL redirects) |
| The field's **Preview and validate** button | The **Schema** row in the entry sidebar, linking to the validator for the saved entry |
| Validator: pick an entry | Validator: type an entry ID, URI or full URL |
| Per-entry Twig (when **Parse Twig in values** is on) ran unrestricted | It runs in Twig's sandbox |
| — | Blade: `$craft->schemy()->render($entry)`; Blade layouts need `@craftHead` for automatic output |
| — | An authenticated GraphQL schema only gets entries its user can view |
