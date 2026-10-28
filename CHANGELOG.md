# Release Notes for Schemy

## 5.0.0

Initial release.

- Schema.org JSON-LD for sections, including singles, channels and structures: pick a type and map
  each property to a field or a short Twig template.
- Entry type overrides within a section, since entry types are shared across sections in Craft 5.
- A Schema field for individual entries, with Inherit, Customize and Disable modes, additional
  JSON-LD nodes, and a preview of the output.
- Values shaped for the property they fill: ISO 8601 dates, `ImageObject`s from image assets,
  `Person`s from users, linked nodes from related entries, plain text from rich text.
- Dot paths for nested values (`address.streetAddress`) and repeated properties for lists.
- One linked `@graph` per page: site identity, `WebSite`, the entry and a `BreadcrumbList`, joined
  by `@id`.
- Autocomplete and suggested properties for common types, and any other type typed by hand.
- Automatic output in `<head>`, or `craft.schemy.render()`, `add()`, `setElement()` and `disable()`
  from templates, and a `beforeRenderGraph` event for plugins and modules.
- `</script>` in content can't close the tag, and a template value that fails to render is logged
  and skipped rather than breaking the page.
- Settings stored in project config. No database tables, no outbound requests.
