---
title: Usage
slug: usage
order: 30
summary: The per-entry field, template helpers, and changing the graph from PHP.
---

## The per-entry field

Add the **Schema (Schemy)** field to any field layout. It has three modes:

- **Inherit** uses the entry type or section schema. The field shows which one applies, e.g.
  "Uses NewsArticle from News".
- **Customize** pre-fills the inherited type. Add only the properties you want to change, and
  they replace the inherited values of the same name while everything else is kept. Change the
  type and the entry starts from a clean slate instead.
- **Disable** outputs no main entity for this entry. The site identity and WebSite nodes still
  appear.

### Additional JSON-LD

The collapsible *Additional JSON-LD* box takes a raw JSON object or array of objects, added to
the page graph as-is. Invalid JSON fails validation when the entry is saved.

### Preview

**Preview JSON-LD** shows the exact graph the front end will output for the last saved or
autosaved version of the entry.

### Twig in entry values

Template values typed on entries are plain text by default and never run as Twig. Otherwise
anyone who can edit an entry could run arbitrary Twig, including queries that publish data you
never meant to expose. Switch on **Parse Twig in values** in the field's settings if you trust
everyone who edits those entries.

Mappings on the section and identity pages are admin-only, so they always parse Twig.

### Other element types

The field works on categories, users, Commerce products, or anything else with a field layout.
Section defaults only apply to entries, so other element types get schema from the field alone.

## Templates

```twig
{# Place the output yourself (also stops automatic output for this request) #}
{{ craft.schemy.render(entry ?? null) }}

{# Add a node to this page's graph #}
{% do craft.schemy.add({'@type': 'Event', name: 'Launch party', startDate: '2026-11-01'}) %}

{# Describe a different element, e.g. on a custom route; pass false for site-wide nodes only #}
{% do craft.schemy.setElement(product) %}

{# Output nothing on this page #}
{% do craft.schemy.disable() %}

{# The graph as an array #}
{% set nodes = craft.schemy.graph(entry) %}
```

## How the graph is linked

Nodes reference each other by `@id`:

- The identity is `https://example.com/#identity`.
- The WebSite is `#website`, with `publisher` pointing at the identity.
- The main entity is `<entry url>#main`. Types that take a `publisher` get one pointing at the
  identity, unless you mapped your own.
- Breadcrumbs are `<entry url>#breadcrumbs`.

## Changing the graph from PHP

```php
use justinholtweb\schemy\events\GraphEvent;
use justinholtweb\schemy\services\Schema;
use yii\base\Event;

Event::on(Schema::class, Schema::EVENT_BEFORE_RENDER_GRAPH, function (GraphEvent $event) {
    // $event->element is the page's element, or null
    $event->graph[] = ['@type' => 'SiteNavigationElement', 'name' => 'Main'];
});
```
