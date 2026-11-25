---
title: Installation
slug: installation
order: 10
summary: Requirements for Craft 5 and Craft 6, install, and the first schema on the page.
---

## Versions

| Schemy | Craft | PHP | Status |
| --- | --- | --- | --- |
| 5.1 | Craft CMS 5.12.1 or later | 8.2+ | Stable |
| 6.0 | Craft CMS 6 | 8.5+ | **Beta**, like Craft 6 itself |

Both lines have the same features: section and entry schema, the per-entry field, the
validator, GraphQL and MCP. Schemy 5.0.0 still installs on any Craft 5 release, without the
validator, GraphQL or MCP.

## Install on Craft 5

```sh
composer require justinholtweb/craft-schemy
php craft plugin/install schemy
```

Or find **Schemy** in the Craft Plugin Store and install it from there.

## Install on Craft 6 (beta)

```sh
composer require justinholtweb/craft-schemy:@beta
php artisan craft:plugin:install schemy
```

Craft 6 and Schemy 6 are both in beta. Try them on a staging site first, and use caution on
production. Moving a Craft 5 site across is covered in
[Upgrading to Craft 6](https://justinholt.com/plugins/craft-schemy/docs/upgrading).

## Your first schema

Schemy outputs JSON-LD from the moment it's installed. With no configuration, every front-end
page gets two nodes: an `Organization` named after your site and a `WebSite` for the current
site. Open any page, view source and look for `application/ld+json` in the `<head>`.

To describe your content:

1. Go to **Schemy → Sections** and pick a section.
2. Set a schema type, for example `BlogPosting`. The input suggests common schema.org types.
3. Map each property to a field or a template: `headline` to Title, `datePublished` to Post
   Date, `description` to your summary field, `image` to an Assets field. Then save.

Every entry in that section now carries the schema, with no template changes.

> **Craft 5:** step 3 has an **Add suggested properties** button that maps `headline`,
> `datePublished`, `author` and the rest for you.

## Free, one edition

Schemy is free, with everything included, on both Craft 5 and Craft 6. It only writes JSON-LD.

## Uninstalling

Schemy installs no database tables. Its settings live in project config, and per-entry values
live in an ordinary field. Uninstalling removes the output, and deleting the field removes the
per-entry values.
