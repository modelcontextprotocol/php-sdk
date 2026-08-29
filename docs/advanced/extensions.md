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

An extension that hands handlers an object of its own implements
`ArgumentProvidingExtensionInterface` on top: a handler declaring a parameter
of a provided type receives it for the request being served, the way it
receives a `RequestContext` — and it stays out of the generated input schemas.

## Tasks (`io.modelcontextprotocol/tasks`)

The [Tasks extension][ext-tasks] (SEP-2663) lets a server hand back a durable
handle instead of holding a connection open for a long-running request. The
client polls `tasks/get` until the task settles, answers anything it asks for
through `tasks/update`, and may `tasks/cancel` it.

```php
use Mcp\Server;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\Psr16TaskStore;
use Mcp\Server\Task\TasksExtension;

$server = Server::builder()
    ->enableExtension(new TasksExtension(new InMemoryTaskStore()))
    ->build();
```

`InMemoryTaskStore` is right for stdio and any single-process runtime and drops
its oldest task past a configurable limit (1000 by default). Under PHP-FPM the
worker that creates a task is not the one polled for it, so use
`Psr16TaskStore` over a shared cache there — a filesystem adapter is enough.

Creating a task is the *server's* decision, made per request by returning a
`CreateTaskResult` from any tool, prompt or resource handler. The extension
hands handlers a `TaskContext` — declare the parameter and it arrives, like a
`RequestContext` does:

```php
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Server\Task\TaskContext;

static function (TaskContext $tasks) use ($queue): CreateTaskResult|string {
    if (!$tasks->isSupported()) {
        return runSynchronously();          // the client cannot poll, so answer now
    }

    $created = $tasks->create(ttlMs: 600_000, pollIntervalMs: 1000);
    $queue->push($created->task->taskId);   // a worker calls $store->save() as it progresses

    return $created;
}
```

`create()` stores the task *before* returning it, so the first `tasks/get`
cannot arrive before the task exists. A client that did not declare the
extension during `initialize` cannot redeem a handle, so `create()` refuses
with `-32021` (missing required client capability) instead of handing one out —
the right answer for a handler whose task support is *required*, and what
`isSupported()` lets an optional one avoid.

The SDK owns storage and the `tasks/get` / `tasks/update` / `tasks/cancel`
surface; **advancing** a task is the application's job. A worker (or a
handler, through `TaskContext::getStore()`) saves the task with a new status
as it goes:

```php
use Mcp\Schema\Enum\TaskStatus;

$store->save($task->with(TaskStatus::Completed, result: ['content' => [/* ... */]]));
```

A task that needs the client's input parks itself as `input_required` with
`inputRequests` (elicitation, sampling or roots requests keyed by name); the
client answers through `tasks/update`, and a `TaskInputHandlerInterface` passed
to `TasksExtension` decides what those answers mean for the task.

Status semantics worth getting right: a tool that ran and reported a problem is
`completed` with `isError` on its result — `failed` is reserved for
protocol-level errors, and carries the error inlined instead of a result.
`Task` refuses to be constructed the other way round.

### On the client

A client declares the extension the same way, and then handles whichever
result shape arrives. `TaskClient` wraps a connected `Client`: its `callTool()`,
`getPrompt()` and `readResource()` return a `CreateTaskResult` when the server
chose to answer with a task, and `get()` / `update()` / `cancel()` drive it:

```php
use Mcp\Client;
use Mcp\Client\Task\TaskClient;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Server\Task\TasksExtension;

$client = Client::builder()
    ->enableExtension(new TasksExtension())
    ->build();
$client->connect($transport);

$tasks = new TaskClient($client);
$result = $tasks->callTool('long_job');

if ($result instanceof CreateTaskResult) {
    do {
        usleep(1000 * ($result->task->pollIntervalMs ?? 1000));
        $task = $tasks->get($result->task->taskId);
    } while (!$task->status->isTerminal());

    $result = CallToolResult::fromArray($task->result);   // once completed
}
```

A task waiting as `input_required` lists its `inputRequests`; answer them with
`update($taskId, ['<key>' => $answer])`, keyed as the requests were, and
`cancel($taskId)` asks the server to stop. The core `Client` itself stays
task-agnostic; `Client::request()` sends any request for code like this.

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

[ext-apps]: https://github.com/modelcontextprotocol/ext-apps
[ext-tasks]: https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2663
