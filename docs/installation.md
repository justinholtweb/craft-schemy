---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the first schema on the page.
---

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later

## Install

```sh
composer require justinholtweb/craft-schemy
php craft plugin/install schemy
```

Or find **Schemy** in the Craft Plugin Store and install it from there.

## Your first schema

Schemy outputs JSON-LD from the moment it's installed. With no configuration, every front-end
page gets two nodes: an `Organization` named after your site and a `WebSite` for the current
site. Open any page, view source and look for `application/ld+json` in the `<head>`.

To describe your content:

1. Go to **Schemy → Sections** and pick a section.
2. Type a schema type, for example `BlogPosting`. The input suggests common schema.org types.
3. Click **Add suggested properties**. Schemy maps `headline`, `datePublished`, `author` and
   the rest to the fields every entry already has.
4. Map anything else, such as `description` to your summary field or `image` to an Assets field.
   Then save.

Every entry in that section now carries the schema, with no template changes.

## Free, one edition

Schemy is free, with everything included. It only writes JSON-LD.

## Uninstalling

Schemy installs no database tables. Its settings live in project config, and per-entry values
live in an ordinary field. Uninstalling removes the output, and deleting the field removes the
per-entry values.
