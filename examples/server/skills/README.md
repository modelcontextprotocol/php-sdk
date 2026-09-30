# MCP Skills Example

Demonstrates the **Skills extension** (`io.modelcontextprotocol/skills`, SEP-2640): serving
multi-step workflow instructions ("skills") to clients. Each skill file is served through the
existing MCP **Resources** primitive, and the extension adds two RPC methods, `skills/list` and
`skills/get`, that return a complete, digest-and-size manifest of a skill's files.

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
files) as a `skill://` resource, and its manifest as a `skills/list`/`skills/get` entry.

## Layout & URIs

```
skills/
├── code-review/
│   ├── SKILL.md                 → skill://code-review/SKILL.md
│   └── references/SECURITY.md   → skill://code-review/references/SECURITY.md
└── acme/billing/refunds/
    └── SKILL.md                 → skill://acme/billing/refunds/SKILL.md
```

## Conventions

- A skill is any folder containing a `SKILL.md`. Its frontmatter `name` **must** equal the final
  segment of the folder path (e.g. `code-review` → `name: code-review`).
- `name`/`description` come from the SKILL.md YAML frontmatter and are always present in the
  `skills/list`/`skills/get` entry; any extra frontmatter passes through verbatim, and is also
  exposed on the SKILL.md resource under the `io.modelcontextprotocol.skills/` `_meta` namespace.
- Supporting files are served with a MIME type guessed from their extension/content.
- Skills are plain files — no PHP handler class is required.
