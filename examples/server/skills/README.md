# MCP Skills Example

Demonstrates the **Skills extension** (`io.modelcontextprotocol/skills`, SEP-2640): serving
multi-step workflow instructions ("skills") to clients through the existing MCP **Resources**
primitive, with zero protocol changes.

## Running

```bash
php examples/server/skills/server.php
```

A single call exposes the whole `skills/` directory:

```php
Server::builder()
    ->setServerInfo('MCP Skills Example', '1.0.0')
    ->addSkillsFromDirectory(__DIR__.'/skills')
    ->build();
```

This auto-enables the `McpSkills` extension and registers every `SKILL.md` (plus supporting
files) as a `skill://` resource.

## Layout & URIs

```
skills/
├── code-review/
│   ├── SKILL.md                 → skill://code-review/SKILL.md
│   └── references/SECURITY.md   → skill://code-review/references/SECURITY.md
└── acme/billing/refunds/
    └── SKILL.md                 → skill://acme/billing/refunds/SKILL.md
```

Plus a discovery index at `skill://index.json` listing every skill.

## Conventions

- A skill is any folder containing a `SKILL.md`. Its frontmatter `name` **must** equal the final
  segment of the folder path (e.g. `code-review` → `name: code-review`).
- `name`/`description` come from the SKILL.md YAML frontmatter; any extra frontmatter is exposed
  under the `io.modelcontextprotocol.skills/` `_meta` namespace.
- Supporting files are served with a MIME type guessed from their extension/content.
- Skills are plain files — no PHP handler class is required.
