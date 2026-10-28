---
title: Configuration
slug: configuration
order: 20
summary: Section and entry type schema, site identity, and the output switches.
---

## Section schema

**Schemy → Sections** lists every section, grouped into singles, channels and structures. Each
section takes a schema type and a list of property mappings. Leave the type blank and the
section outputs no main entity.

A mapping is a property name plus a source:

| Source | What it does |
| --- | --- |
| Field | Reads a custom field from the layout, or one of `title`, `url`, `slug`, `postDate`, `dateCreated`, `dateUpdated`, `expiryDate`, `author` |
| Template | Renders a Twig object template against the entry, e.g. `{title}` or `{{ entry.summary }}`. Plain text works too. |

Field values are converted based on what the property expects:

| Value | Becomes |
| --- | --- |
| Date | ISO 8601 string |
| Image asset on an image property (`image`, `logo`, …) | `ImageObject` with URL, width, height and alt text as caption |
| Any other asset | Its URL |
| User | `Person` with their full name |
| Related entry on `author`, `publisher`, `location`, … | `Person`, `Organization` or `Place` with name and URL |
| Related entry elsewhere | Its title |
| Rich text | Plain text, tags stripped |
| Dropdown, checkboxes, radio buttons | The option labels |
| Several values | A list |

### Nested values and lists

Property names accept dot paths, so `address.@type` → `PostalAddress` plus
`address.streetAddress` → `{street}` builds a nested `PostalAddress`.

Mapping the same property twice outputs a list, which is how you give `sameAs` several URLs.

### Structured output from a template

If a template's output starts with `{` or `[` and parses as JSON, Schemy inserts it as data
instead of a string:

```twig
{{ entry.faqs.all()|map(f => {
    '@type': 'Question',
    name: f.question,
    acceptedAnswer: {'@type': 'Answer', text: f.answer|striptags}
})|json_encode }}
```

## Entry type overrides

On a section's page, each of its entry types can switch on its own schema. In Craft 5 an entry
type can belong to several sections, so the override is scoped to this section only. Your
`article` type can be a `NewsArticle` in News and a `BlogPosting` in Blog.

## Site identity

**Schemy → Site Identity** describes who runs the site: usually an `Organization`, a specific
business type such as `LocalBusiness` or `Restaurant`, or a `Person`. It appears on every page,
and creative works such as articles use it as their `publisher`.

Identity values are always templates, and they render per site, so `{{ siteName }}` and
`{{ currentSite.handle }}` give each site its own identity.

## Output switches

**Schemy → Settings** has four switches:

| Setting | Default | |
| --- | --- | --- |
| Output automatically | On | Adds the script tag to `<head>` on every front-end page |
| Include site identity | On | The Organization/Person node |
| Include WebSite | On | A WebSite node for the current site |
| Include breadcrumbs | On | A BreadcrumbList from the home page and, in structures, the entry's ancestors |

## Project config

All of the above is plugin settings, so it is stored in project config and deploys with your
schema. With `allowAdminChanges` off, the Schemy pages are read-only. Make changes locally and
deploy them.

Any setting can be overridden in `config/schemy.php`:

```php
<?php

return [
    'includeBreadcrumbs' => false,
];
```
