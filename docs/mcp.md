---
title: MCP
slug: mcp
order: 37
summary: Tools for AI assistants connected to Craft's MCP server.
---

Craft has an MCP server that AI assistants can connect to (Craft 5.12.1 and later, and Craft 6). Schemy adds tools to it, so an
assistant can read and check structured data and, for admins, set it up.

| Tool | Who can use it | |
| --- | --- | --- |
| `schemy_entry_jsonld_get` | Anyone who can view the entry | An entry's JSON-LD with validation results |
| `schemy_jsonld_validate` | Any connected user | Validates a JSON-LD string |
| `schemy_types_list` | Any connected user | Known types, their properties, and rich result rules |
| `schemy_settings_get` | Admins | Output switches, identity, sidebar label and icon |
| `schemy_sections_list` | Admins | Each section's schema and entry type overrides |
| `schemy_section_schema_set` | Admins, with `allowAdminChanges` | Sets a section's or entry type's schema |
| `schemy_identity_set` | Admins, with `allowAdminChanges` | Sets the site identity |
| `schemy_settings_set` | Admins, with `allowAdminChanges` | Changes the switches and sidebar |

Write tools need the token's **Full access** scope, as with Craft's own admin tools, and are
hidden on environments where `allowAdminChanges` is off.

The Schema field also describes its value to Craft's own entry tools, so an assistant that can
edit an entry can set its schema mode, type and properties the same way it sets other fields.

Some things to ask an assistant:

- "Check the JSON-LD on the five most recent news entries and tell me what's missing for rich results."
- "Set up Recipe schema on the recipes section using the fields it already has."
- "Which sections have no schema yet?"
