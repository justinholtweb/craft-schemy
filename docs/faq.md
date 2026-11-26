---
title: FAQ
slug: faq
order: 50
summary: Common questions about schema.org markup with Schemy.
---

## Why not just use SEOmatic?

If you want meta tags, sitemaps, social cards and structured data in one place, use SEOmatic.
It's excellent.

Schemy is for sites that only want the structured data, or that already handle the rest some
other way. It adds no meta tags, no sitemap, no tables, and no settings unrelated to schema.

## What does it cost?

Nothing. Schemy is free, one edition, everything included.

## Which schema types are supported?

All of them. The type and property inputs suggest about forty common types, including Article,
BlogPosting, NewsArticle, WebPage, FAQPage, Event, Product, LocalBusiness, Person, Recipe,
JobPosting, Course and VideoObject. You can type any schema.org type or property name; the
suggestions are a convenience, not a limit.

## Does it work with Craft 6?

Yes. Schemy 6 is a native Craft 6 plugin, in beta while Craft 6 is. It has the same features as
Schemy 5.1, plus Blade support. Settings and entry values carry over: see
[Upgrading to Craft 6](https://justinholt.com/plugins/craft-schemy/docs/upgrading).

## Does it validate my markup?

Yes. The [validator](https://justinholt.com/plugins/craft-schemy/docs/validator) checks structure,
likely typos, dates, URLs and the properties Google's rich results need, from the control panel,
the command line and MCP. It runs offline and approximates Google's rules, so Google's Rich
Results Test is still the final word on whether a rich result shows.

## Can editors change schema without an admin?

Yes. Every entry with the Schema field in its layout can inherit, customize or disable its own
schema. Section, entry type and identity schema need their own
[permissions](https://justinholt.com/plugins/craft-schemy/docs/configuration#permissions), and
can be edited on production, because they're stored in the database rather than project config.

## Is it multi-site aware?

Yes. Output is built for the page's site, the identity renders per site, and field values are
read from the entry in that site.

## Does it slow pages down?

Schemy builds the graph once per page from the entry already loaded for that page. It reads only
the fields you mapped, so the extra cost is whatever those fields cost to load: usually nothing
for plain fields, one query for a relation.
