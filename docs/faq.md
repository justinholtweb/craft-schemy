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

## Does it validate my markup?

No. Schemy guarantees the output is valid JSON and correctly linked, but whether Google shows a
rich result depends on Google's own requirements for each type. Test pages with Google's Rich
Results Test.

## Can editors change schema without an admin?

Yes, through the per-entry field. Section, entry type and identity settings are admin-only
because they live in project config.

## Is it multi-site aware?

Yes. Output is built for the page's site, the identity renders per site, and field values are
read from the entry in that site.

## Does it slow pages down?

Schemy builds the graph once per page from the entry already loaded for that page. It reads only
the fields you mapped, so the extra cost is whatever those fields cost to load: usually nothing
for plain fields, one query for a relation.
