# Protocol Extensions

MCP protocol extensions advertise additional, optional capabilities during the initialize handshake.
A server opts in via `Builder::enableExtension()`:

```php
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->enableExtension(new McpApps())
    ->build();
```

Pass one or more `ServerExtensionInterface` instances; multiple extensions can
be enabled in a single call. Enabling the same extension twice throws a
`LogicException`.

> Note: extensions enabled via `enableExtension()` are merged into the
> `extensions` capability even when you supply your own `ServerCapabilities` via
> `setCapabilities()`. An enabled extension overrides any entry under the same
> id already present in those capabilities.

## MCP Apps (`io.modelcontextprotocol/ui`)

The [MCP Apps extension][ext-apps] lets servers expose interactive HTML UIs as
resources. Clients that support it render them in sandboxed iframes and bridge
tool calls between the iframe (the *View*) and the server via the host.

A UI consists of two pieces wired together by `_meta.ui`:

1. **A resource** with URI scheme `ui://` and MIME type
   `text/html;profile=mcp-app`, returning the HTML body.
2. **A tool** linked to that resource via `UiToolMeta`, so the client knows to
   open the UI when the tool is invoked.

```php
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiResourceContentMeta;
use Mcp\Schema\Extension\Apps\UiResourceCsp;
use Mcp\Schema\Extension\Apps\UiResourcePermissions;
use Mcp\Schema\Extension\Apps\UiToolMeta;

$server = Server::builder()
    ->enableExtension(new McpApps())
    ->addResource(
        fn () => new TextResourceContents(
            uri: 'ui://my-app',
            mimeType: McpApps::MIME_TYPE,
            text: file_get_contents(__DIR__.'/app.html'),
            meta: ['ui' => new UiResourceContentMeta(
                csp: new UiResourceCsp(connectDomains: ['https://api.example.com']),
                permissions: new UiResourcePermissions(geolocation: true),
                prefersBorder: true,
            )],
        ),
        'ui://my-app',
        mimeType: McpApps::MIME_TYPE,
        meta: ['ui' => McpApps::resourceMarker()],
    )
    ->addTool(
        $myToolHandler,
        'my_tool',
        meta: ['ui' => new UiToolMeta(
            resourceUri: 'ui://my-app',
            visibility: [ToolVisibility::Model, ToolVisibility::App],
        )],
    )
    ->build();
```

Note the two distinct `_meta.ui` shapes: the resource *descriptor* (its
`resources/list` entry) carries only an empty marker — `McpApps::resourceMarker()` —
flagging it as an MCP App, while the resource *content* returned by `resources/read`
carries the structured `UiResourceContentMeta` with the actual CSP and permission
configuration.

### Server-side DTOs

| Class | Purpose |
| --- | --- |
| `McpApps` | Extension marker; provides `EXTENSION_ID`, `MIME_TYPE`, `URI_SCHEME` constants. |
| `UiToolMeta` | Tool `_meta.ui` payload: `resourceUri` + `visibility`. |
| `ToolVisibility` | Enum: `Model`, `App`. |
| `UiResourceContentMeta` | Resource content `_meta.ui`: `csp`, `permissions`, `domain`, `prefersBorder`. |
| `UiResourceCsp` | CSP allow-lists: `connectDomains`, `resourceDomains`, `frameDomains`, `baseUriDomains`. |
| `UiResourcePermissions` | Sandbox permissions: `camera`, `microphone`, `geolocation`, `clipboardWrite`. |

### Writing the HTML view

The View and host exchange `JSONRPCMessage` **objects** (not JSON strings) via
`window.parent.postMessage`. Before the host forwards `tools/call`,
`tool-input`, or `tool-result`, the View must complete the spec-mandated
handshake:

1. View → Host: `ui/initialize` request
2. Host → View: response with `hostCapabilities`, `hostInfo`, `hostContext`
3. View → Host: `ui/notifications/initialized`
4. View → Host: `ui/notifications/size-changed` whenever the iframe wants to
   resize

See the [`ext-apps` repository][ext-apps] for the full protocol, official
TypeScript SDK (`@modelcontextprotocol/ext-apps`), and view-side examples. A
working minimal view is included in
[`examples/server/mcp-apps/weather-app.html`](../examples/server/mcp-apps/weather-app.html).

## Skills (`io.modelcontextprotocol/skills`)

The [Skills extension][ext-skills] (SEP-2640) lets servers ship **skills** —
multi-step workflow instructions that tell an agent *how to orchestrate* tools to
reach a goal. Skills are served through the existing **Resources** primitive with
zero protocol changes: each skill is a `skill://<skill-path>/SKILL.md` resource
(plus any supporting files), and the server advertises an empty
`io.modelcontextprotocol/skills` capability.

The simplest way to expose a directory of skills is `addSkillsFromDirectory()`,
which auto-enables the extension and registers every skill it finds:

```php
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->addSkillsFromDirectory(__DIR__.'/skills')
    ->build();
```

Given this layout, the following `skill://` resources are registered:

```
skills/
├── code-review/
│   ├── SKILL.md                 → skill://code-review/SKILL.md
│   └── references/SECURITY.md   → skill://code-review/references/SECURITY.md
└── acme/billing/refunds/
    └── SKILL.md                 → skill://acme/billing/refunds/SKILL.md
```

Each `SKILL.md` is served as `text/markdown`. Its YAML frontmatter supplies the
resource `name`/`description`; any remaining frontmatter keys are exposed under the
`io.modelcontextprotocol.skills/` `_meta` namespace. Supporting files are served
with a MIME type guessed from their extension/content.

```yaml
---
name: code-review
description: Review a pull request for correctness, security, and style.
version: 1.0.0
tags: [review, quality]
---

# Code Review
...
```

> The frontmatter `name` **must** equal the final segment of the skill's directory
> path (`code-review/` → `name: code-review`); a mismatch throws an
> `InvalidArgumentException`.

By default a discovery index is also served at `skill://index.json` (an
[Agent Skills][agent-skills] discovery document listing every skill). Skills also
appear as normal entries in `resources/list`, so a large skill tree pages via
`resources/list` cursors. Pass `withDiscoveryIndex: false` to skip the index.

Parsing `SKILL.md` frontmatter requires the [`symfony/yaml`][symfony-yaml]
component, which is a dependency of this SDK.

### Server-side classes

| Class | Purpose |
| --- | --- |
| `McpSkills` | Extension marker; provides `EXTENSION_ID`, `MIME_TYPE`, `URI_SCHEME`, `ENTRY_POINT`, `DISCOVERY_URI`, `META_PREFIX` constants. |
| `SkillProvider` | Walks a directory and registers each skill (and its files) as `skill://` resources. |
| `FrontmatterParser` | Splits a `SKILL.md` into its YAML frontmatter and markdown body. |
| `SkillMetadata` | Value object for parsed frontmatter: `name`, `description`, `extra`. |
| `SkillDiscoveryIndex` | The `skill://index.json` document: `$schema` + `skills`. |
| `SkillDiscoveryEntry` | One index entry: `name`, `type`, `url`, `description`. |
| `SkillType` | Enum: `SkillMd` (`skill-md`), `McpResourceTemplate` (`mcp-resource-template`). |

A complete example lives in
[`examples/server/skills/`](../examples/server/skills/).

[ext-apps]: https://github.com/modelcontextprotocol/ext-apps
[ext-skills]: https://github.com/modelcontextprotocol/experimental-ext-skills
[agent-skills]: https://agentskills.io
[symfony-yaml]: https://symfony.com/doc/current/components/yaml.html
