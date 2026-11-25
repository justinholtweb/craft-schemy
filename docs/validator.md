---
title: Validator
slug: validator
order: 35
summary: Check JSON-LD for broken structure, typos, bad dates and URLs, and missing rich result properties.
---

Schemy has a validator that runs on your server and sends nothing anywhere. It checks:

- **Structure**: valid JSON, a schema.org `@context`, a `@type` on every top-level node, a well-formed `@graph`.
- **Typos**: type and property names that are one or two letters off a known one (`Artcle`, `autor`). A mistyped type is still checked as the type it was probably meant to be.
- **Values**: ISO 8601 dates, absolute URLs for URL and image properties, image objects with a `url`, empty values, and HTML left in text.
- **`@id`s**: the same `@id` on nodes of different types, relative `@id`s, and references to nodes that aren't in the graph.
- **Rich results**: properties Google requires or recommends for Article, Event, Product, Recipe, JobPosting, LocalBusiness, FAQPage, Review, VideoObject, Course, SoftwareApplication, BreadcrumbList and others, including nested Offers, Ratings, Questions and ListItems.

Each issue has a severity:

| Severity | Meaning |
| --- | --- |
| Error | The markup is broken, or a rich result can't be shown |
| Warning | Probably a mistake, or recommended properties are missing |
| Notice | Worth a look, but often fine |

The rich result rules are a summary of Google's documentation, so they catch gaps early. Google
changes its rules, and the [Rich Results Test](https://search.google.com/test/rich-results) is
still the final word.

## In the control panel

**Utilities → Schema Validator** (also **Schemy → Validator**) validates what Schemy outputs for an
entry, given its ID, URI or full URL, or any JSON-LD you paste in, including a whole
`<script type="application/ld+json">` tag copied from page source. Access follows Craft's utility
permissions.

The **Schema** row in an entry's sidebar summarizes the results for the saved entry and links to them.

## From the command line

```sh
php artisan schemy:validate --entry=42
php artisan schemy:validate --entry=news/harbour-reopens
php artisan schemy:validate --section=news --site=fr --limit=500
php artisan schemy:validate --file=build/schema.json
```

With `--section`, only entries with errors list their issues; add `--all` to see warnings and
notices too. The command exits with code 1 when it finds an error (2 on bad usage), so it can stop a
deploy or fail a CI job.

## From MCP and PHP

AI assistants connected to Craft's MCP server can call `schemy_jsonld_validate` and
`schemy_entry_jsonld_get`. See [MCP](https://justinholt.com/plugins/craft-schemy/docs/mcp).

```php
use JustinHoltWeb\Schemy\Services\Validator;

$result = app(Validator::class)->validateElement($entry);
$result->hasErrors();
$result->toArray(); // ['valid' => bool, 'errors' => int, 'warnings' => int, 'notices' => int, 'issues' => [...]]
```
