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

On a section's page, each of its entry types can switch on its own schema. In Craft an entry
type can belong to several sections, so the override is scoped to this section only. Your
`article` type can be a `NewsArticle` in News and a `BlogPosting` in Blog.

## Site identity

**Schemy → Site Identity** describes who runs the site: usually an `Organization`, a specific
business type such as `LocalBusiness` or `Restaurant`, or a `Person`. It appears on every page,
and creative works such as articles use it as their `publisher`.

Identity values are always templates, and they render per site, so `{{ siteName }}` and
`{{ currentSite.handle }}` give each site its own identity.

## Output switches

**Settings → Plugins → Schemy** has four switches (on Craft 5: **Schemy → Settings**):

| Setting | Default | |
| --- | --- | --- |
| Output automatically | On | Adds the script tag to `<head>` on every front-end page |
| Include site identity | On | The Organization/Person node |
| Include WebSite | On | A WebSite node for the current site |
| Include breadcrumbs | On | A BreadcrumbList from the home page and, in structures, the entry's ancestors |

## Sidebar label and icon

Under **Control Panel** on the same page, **Sidebar label** renames Schemy in the control panel
sidebar (say, to "Structured Data"), **Sidebar icon** picks any control panel icon, and **Custom
SVG icon** takes a pasted `<svg>` element instead, sanitized every time it's drawn. Leave them
empty for the defaults. They're stored in project config with the other settings, as `navLabel`,
`navIcon` and `navIconSvg`. An override in Craft's own Settings → Control Panel Navigation wins
over these.

## Where things are stored

Section schema, entry type overrides and the site identity are content, so they're stored in
Schemy's own database table. They can be edited in any environment, including production with
`allowAdminChanges` off, by anyone with the permissions below.

The output switches and the sidebar label and icon are plugin settings. They're stored in project
config and deploy with the rest of your schema. With `allowAdminChanges` off, that page is
read-only: change it locally and deploy.

> Before Schemy 5.2.0 (and 6.0.0-beta.2), section schema and the identity were project config
> too. The update moves them into the table for you.

## Permissions

Under **Schemy** in a user group's or user's permissions:

| Permission | Lets people |
| --- | --- |
| Access Schemy | See Schemy in the sidebar (Craft adds this one for every plugin) |
| Edit section and entry type schema | Use **Schemy → Sections**, and the matching MCP tools, for sections whose entries they can view |
| Edit site identity | Use **Schemy → Site Identity**, and the matching MCP tool |

Mapped field values end up in each page's public JSON-LD, so a section's schema can only be
edited by people who can also view its entries. Admins have all of them. The **Schema Validator** utility has Craft's usual utility permission,
and the settings page is admin-only.

Template values on these pages can use Twig, but only an admin's. When someone who isn't an
admin saves a page, Twig in its values isn't run: they're output as text, with only
`{{ siteName }}` and `{{ siteUrl }}` filled in. Map entry values with the **Field** source instead.
(Even Twig's sandbox would let a template reach related data, such as an author's email, and
publish it.)

The page shows a note while that's the case. It stays that way when an admin saves the page,
until the admin ticks **Run these values as Twig** after checking them, or no value contains Twig.

## Moving schema between environments

Since section schema and the identity no longer travel with `project.yaml`, export them from one
environment and import them into another:

```sh
# Craft 5
php craft schemy/schemas/export --file=schemy.json
php craft schemy/schemas/import --file=schemy.json

# Craft 6
php artisan schemy:schemas:export --file=schemy.json
php artisan schemy:schemas:import --file=schemy.json
```

Sections and entry types are matched by UID, then by handle. Sections not in the file are left
as they are. The file records which rows were saved by non-admins, and an import keeps that; add
`--trusted` to vouch for every row in a file you've checked.

## Config file

The plugin settings can be overridden in `config/craft/schemy.php` (on Craft 5: `config/schemy.php`):

```php
<?php

return [
    'includeBreadcrumbs' => false,
];
```
