# Protocol extensions

MCP protocol extensions advertise additional, optional capabilities alongside the regular ones.
A server opts in with `Builder::enableExtension()`. The SDK announces the extension in the
`initialize` result of the handshake era, and in the `server/discover` result on revision
`2026-07-28`:

```php
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Server\Server;

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

A handshake-era client on a server without sessions always counts as not supporting the extension, see
[Serving without sessions](../run/sessions.md#serving-without-sessions).

## Writing your own extension

An extension implements `Mcp\Schema\Extension\ExtensionInterface`. If it only announces a
capability, extend `AbstractExtension` and define the identifier and the capability payload:

```php
use Mcp\Schema\Extension\AbstractExtension;
use Mcp\Schema\Extension\ExtensionIdentifier;

final class AuditExtension extends AbstractExtension
{
    public function getId(): ExtensionIdentifier
    {
        // a prefix is required, e.g. "com.example/"
        return new ExtensionIdentifier('com.example/audit');
    }

    public function getCapabilities(): array
    {
        // sent under capabilities.extensions["com.example/audit"]
        return ['retentionDays' => 30];
    }
}
```

Enable it with `Builder::enableExtension(new AuditExtension())`.

!!! warning
    A prefix whose second label is `modelcontextprotocol` or `mcp` (e.g. `io.modelcontextprotocol/`) is reserved for
    official extensions. The SDK doesn't refuse such an identifier, so choose a prefix of your own.

### Custom methods

An extension that adds JSON-RPC methods also implements `getMessages()`, which returns the
request and notification classes of the new methods, so the SDK can parse them. Its handlers
come from the interfaces of the side it is enabled on:

| Side | Interface | Contributes |
| --- | --- | --- |
| Server | `Mcp\Server\Extension\ServerExtensionInterface` | `getRequestHandlers()` and `getNotificationHandlers()`: [handlers](custom-handlers.md) for the client's requests and notifications |
| Client | `Mcp\Client\Extension\ClientExtensionInterface` | `getRequestHandlers()` and `getNotificationHandlers()` for the server's requests and notifications |

Handlers differ per side, so an extension with methods for both sides ships one class per
side, sharing the message classes.
Server notification handlers
[don't run on revision `2026-07-28`](custom-handlers.md#protocol-2026-07-28).

A method name belongs to one message class. `enableExtension()` throws a `LogicException`
when an extension defines a core MCP method, such as `tools/call` or `notifications/cancelled`,
or a method another enabled extension already defines. Replacing what a core method does
stays possible with a [custom handler](custom-handlers.md), which keeps the method's types.

### Requiring another extension

An extension that only works alongside another one implements
`Mcp\Schema\Extension\DependentExtensionInterface`:

```php
use Mcp\Schema\Extension\DependentExtensionInterface;
use Mcp\Schema\Extension\ExtensionIdentifier;

final class SearchExtension implements DependentExtensionInterface
{
    // getId(), getCapabilities(), getMessages()...

    public function getRequiredExtensions(): array
    {
        return [new ExtensionIdentifier('com.example/catalog')];
    }
}
```

The requirement is local and never sent to the peer. `build()` checks it, so the required
extension can be enabled before or after the one requiring it. When it is missing, `build()`
throws a `LogicException` naming both extensions, and no client or server is created.

## Extension points (SEP-3371)

[SEP-3371](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/3371) defines
the extension points an SDK offers so that an extension can ship as its own package. This
SDK implements SEP-3371 for server and client: registration and custom methods; middleware not yet.

| Extension point | Server | Client |
| --- | --- | --- |
| Registration | `Server\Builder::enableExtension()` | `Client\Builder::enableExtension()` |
| Capabilities | `ExtensionInterface::getCapabilities()`, read back with `ClientGateway::supportsExtension()` | `ExtensionInterface::getCapabilities()`, read back with `Client::getServerCapabilities()` |
| Dependencies | `DependentExtensionInterface` | `DependentExtensionInterface` |
| Receiving custom methods | `getMessages()`, `ServerExtensionInterface` | `getMessages()`, `ClientExtensionInterface` |
| Sending custom methods | `ClientGateway::request()` and `ClientGateway::notify()`, from the `RequestContext` of a handler | `Client::sendRequest()` and `Client::notify()` |
| Middleware | not available yet | not available yet |

Registration happens on the builder, before any message is processed:

* Each extension is installed whole or not at all. When `enableExtension()` throws, none
  of the rejected extension's capabilities, messages or handlers are kept.
* Enabling the same identifier twice, a core method name, or a method another extension
  defines throws a `LogicException`.
* Dependencies are checked by `build()`, whatever order the extensions were enabled in.

`ClientGateway::request()` needs a session, since the client answers in a request of its
own, and revision `2026-07-28` has no server-initiated requests: a handler returns an
`InputRequiredResult` there instead.

Handlers run in the order they were registered, whether they come from an extension or
from `addRequestHandler()`/`addNotificationHandler()`. A request goes to the first handler
that supports it. On the server, these handlers run before the SDK's built-in ones, and
every notification handler that supports a notification runs. On the client, the built-in
`notifications/progress` handler runs first, and a notification reaches only the first
handler that supports it.

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
use Mcp\Server\Server;

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
configuration. The marker is a convention of this SDK. The MCP Apps specification doesn't
define it: it allows the structured metadata on the descriptor too, and its example descriptor
has no `_meta`.

### Attribute-based discovery

The same linkage works with `#[McpResource]` / `#[McpTool]`, since both accept a
`meta` array and PHP allows `new` in attribute arguments. The one difference is the
descriptor marker: `McpApps::resourceMarker()` is a method call and cannot appear
in an attribute, so spell it as `new \stdClass()` there.

```php
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Extension\Apps\McpApps;
use Mcp\Schema\Extension\Apps\ToolVisibility;
use Mcp\Schema\Extension\Apps\UiToolMeta;
use Mcp\Server\Capability\Attribute\McpResource;
use Mcp\Server\Capability\Attribute\McpTool;

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

After the handshake, the View sends `ui/notifications/size-changed` whenever the
iframe wants to resize.

See the [`ext-apps` repository][ext-apps] for the full protocol, official
TypeScript SDK (`@modelcontextprotocol/ext-apps`), and view-side examples. A
working minimal view is included in
[`examples/server/mcp-apps/weather-app.html`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/mcp-apps/weather-app.html).

[ext-apps]: https://github.com/modelcontextprotocol/ext-apps
