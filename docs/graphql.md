---
title: GraphQL
slug: graphql
order: 36
summary: Fetch JSON-LD for headless front ends, and read or write the Schema field over GraphQL.
---

## The `schemy` query

Enable **Schemy → Query JSON-LD with the `schemy` query** in a GraphQL schema, then ask for an
entry by ID or URI:

```graphql
{
  schemy(uri: "news/harbour-reopens", site: "default") {
    jsonLd
    script
  }
}
```

| Argument | |
| --- | --- |
| `id` | An entry ID |
| `uri` | An entry URI. Use `__home__` for the home page. |
| `site` / `siteId` | The site. Defaults to the primary site. |

Leave out `id` and `uri` to get only the site-wide nodes (identity and `WebSite`), which is handy
for pages that aren't entries.

`jsonLd` is the document encoded as JSON. `script` is the whole
`<script type="application/ld+json">` tag, escaped so it's safe to output as-is.

The query respects the schema's scope: it only finds entries in sections and sites the schema
can read, and returns `null` otherwise. On Craft 6, an authenticated schema only finds entries its
user can view.

## The Schema field

The Schema field returns its settings and the rendered document:

```graphql
{
  entry(section: "news", slug: "harbour-reopens") {
    ... on article_Entry {
      seoSchema {
        mode        # inherit, override or disable
        type
        properties { property source value }
        extraJson
        jsonLd      # the full document for this entry
      }
    }
  }
}
```

It can also be set in mutations with a `SchemyFieldInput`:

```graphql
mutation {
  save_news_article_Entry(id: 42, seoSchema: {
    mode: override
    type: "NewsArticle"
    properties: [{ property: "dateline", source: template, value: "Portland" }]
  }) { id }
}
```

When the type matches the inherited one, these rows layer onto the inherited properties. A
different type starts fresh.
