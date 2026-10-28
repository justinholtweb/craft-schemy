---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Missing output, empty properties, and running alongside an SEO plugin.
---

## Nothing is output

- **The template has no `<head>`.** Automatic output goes where Craft puts head HTML. Templates
  that never output `</head>`, such as XML feeds, get nothing, by design. Use
  `{{ craft.schemy.render() }}` if you need it elsewhere.
- **Output automatically is off**, and the template doesn't call `craft.schemy.render()`.
- **The page called `craft.schemy.disable()`**, perhaps in a layout or a partial.

## The main entity is missing

- The section has no type set, and neither does the entry type or the entry.
- The entry's Schemy field is set to **Disable**.
- The page isn't an entry's own URL. On custom routes, Craft has no matched element, so tell
  Schemy which one to describe with `craft.schemy.setElement()`.

## A property is missing

Schemy leaves out properties that resolve to nothing, so a mapped field that's empty on this
entry produces no property rather than an empty string. Also check:

- **Template errors.** A template that fails to render is skipped and logged as a warning that
  starts with `Schemy couldn't render template`. It never breaks the page. Check
  `storage/logs/web-*.log`.
- **The field isn't in this entry's layout.** The field dropdown marks handles that aren't in
  the current layout as *not in this layout*.
- **Twig in entry values is off.** On the per-entry field, `{title}` is output literally unless
  the field allows Twig.

## Running alongside SEOmatic or another SEO plugin

Both plugins will output JSON-LD. That's valid, but search engines then see two descriptions of
the same page. Turn off the other plugin's structured data, or turn off Schemy's identity and
WebSite nodes and keep only its main entities.

## The settings pages are read-only

`allowAdminChanges` is off in this environment. Schemy settings are project config: change them
locally and deploy.
