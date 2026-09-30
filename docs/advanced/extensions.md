# Protocol Extensions

MCP protocol extensions advertise additional, optional capabilities alongside the regular ones —
during the `initialize` handshake, or on revision `2026-07-28` (which has no handshake) inside the
capabilities that travel with every request. A server opts in via `Builder::enableExtension()` and
the SDK places the advertisement correctly for whichever era the client speaks:

```php
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->enableExtension(new McpApps())
    ->build();
```

Pass one or more `ExtensionInterface` instances; multiple extensions can
be enabled in a single call. Enabling the same extension twice throws a
`LogicException`.

Clients (hosts) advertise the extensions they support the same way, via
`Client\Builder::enableExtension()`; the payload lands under
`capabilities.extensions` in the initialize request — or, on a modern revision,
under the client capabilities carried in each request's `_meta` envelope.

> Note: extensions enabled via `enableExtension()` are merged into the
> `extensions` capability even when you supply your own `ServerCapabilities` /
> `ClientCapabilities` via `setCapabilities()`. An enabled extension overrides
> any entry under the same id already present in those capabilities.

## Checking what the client negotiated

An extension is only in effect when both sides advertise it. On the server, a
handler can ask the `ClientGateway` from the injected `RequestContext` whether the
connected client did:

```php
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\RequestContext;

public function getWeather(string $city, RequestContext $context): string
{
    if (!$context->getClientGateway()->supportsExtension(McpApps::EXTENSION_ID)) {
        // text-only fallback for hosts without MCP Apps support
    }
    // ...
}
```

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

### Attribute-based discovery

The same linkage works with `#[McpResource]` / `#[McpTool]`, since both accept a
`meta` array and PHP allows `new` in attribute arguments. The one difference is the
descriptor marker: `McpApps::resourceMarker()` is a method call and cannot appear
in an attribute, so spell it as `new \stdClass()` there.

```php
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiToolMeta;

final class WeatherApp
{
    #[McpResource(uri: 'ui://my-app', mimeType: McpApps::MIME_TYPE, meta: ['ui' => new \stdClass()])]
    public function view(): TextResourceContents { /* as above */ }

    #[McpTool(name: 'my_tool', meta: ['ui' => new UiToolMeta(resourceUri: 'ui://my-app', visibility: [ToolVisibility::Model, ToolVisibility::App])])]
    public function myTool(string $city): string { /* ... */ }
}
```

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
[`examples/server/mcp-apps/weather-app.html`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/mcp-apps/weather-app.html).

## Skills (`io.modelcontextprotocol/skills`)

The [Skills extension][ext-skills] (SEP-2640) lets servers ship **skills** —
multi-step workflow instructions that tell an agent *how to orchestrate* tools to
reach a goal. Each skill file is served through the existing **Resources**
primitive (`skill://<skill-path>/SKILL.md` plus any supporting files), and the
extension adds two mandatory RPC methods:

- `skills/list` — enumerates the skills a server serves, paginated like
  `resources/list`.
- `skills/get` — returns the entry for a single skill by its `SKILL.md` URI.

Both return a `Skill` entry: the skill's frontmatter verbatim, and a complete,
`{uri, digest, size}` manifest of every file the skill comprises (`SKILL.md`
included), so a host can build its registry, present the skill for approval, and
verify every later read without fetching anything first.

The simplest way to expose a directory of skills is `addSkillsFromDirectory()`,
which auto-enables the extension and registers every skill it finds:

```php
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->addSkillsFromDirectory(__DIR__.'/skills')
    ->build();
```

Given this layout, the following `skill://` resources are registered, and a
matching `Skill` entry is added to the `skills/list`/`skills/get` catalog:

```
skills/
├── code-review/
│   ├── SKILL.md                 → skill://code-review/SKILL.md
│   └── references/SECURITY.md   → skill://code-review/references/SECURITY.md
└── acme/billing/refunds/
    └── SKILL.md                 → skill://acme/billing/refunds/SKILL.md
```

Each `SKILL.md` is served as `text/markdown`. Its YAML frontmatter's `name` and
`description` become the resource `name`/`description`; any remaining frontmatter
keys are exposed under the `io.modelcontextprotocol.skills/` `_meta` namespace on
the resource, and pass through verbatim in the `skills/list`/`skills/get` entry's
`frontmatter`. Supporting files are served with a MIME type guessed from their
extension/content.

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
> path (`code-review/` → `name: code-review`), and `description` is required; a
> violation throws an `InvalidArgumentException`.

The extension fixes two per-skill limits so every conforming host knows what it
must accept: 512 resources and 16 MiB total content. `addSkillsFromDirectory()`
throws if a skill exceeds either.

Parsing `SKILL.md` frontmatter requires the [`symfony/yaml`][symfony-yaml]
component, which is a dependency of this SDK.

### Server-side classes

| Class | Purpose |
| --- | --- |
| `McpSkills` | Extension; provides `EXTENSION_ID`, `MIME_TYPE`, `URI_SCHEME`, `ENTRY_POINT`, `META_PREFIX` constants and the `skills/list`/`skills/get` handlers. |
| `SkillProvider` | Walks a directory and registers each skill (and its files) as `skill://` resources, recording each skill's manifest in a `SkillRegistry`. |
| `SkillRegistry` | The skills a server serves, keyed by `SKILL.md` URI; backs `skills/list`/`skills/get`. |
| `FrontmatterParser` | Splits a `SKILL.md` into its YAML frontmatter and markdown body. |
| `SkillMetadata` | Value object for parsed frontmatter: `name`, `description`, `extra`. |
| `Skill` | One `skills/list`/`skills/get` entry: `uri`, `frontmatter`, `resources`. |
| `SkillResource` | One file of a skill's manifest: `uri`, `digest`, `size`. |

A complete example lives in
[`examples/server/skills/`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/skills/).

[ext-skills]: https://github.com/modelcontextprotocol/ext-skills
[symfony-yaml]: https://github.com/symfony/yaml

[ext-apps]: https://github.com/modelcontextprotocol/ext-apps
