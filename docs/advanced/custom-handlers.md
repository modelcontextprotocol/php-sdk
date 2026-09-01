# Custom message handlers

**Low-level escape hatch.** Custom message handlers run before the SDK's built-in handlers and give you total control over
individual JSON-RPC messages. They do not receive the builder's registry, container, or discovery output unless you pass
those dependencies in yourself.

!!! warning
    A custom handler replaces the built-in handler of the method it takes. A custom `tools/list` handler hides the tools
    you registered elsewhere, unless it loads them itself. Other methods keep their built-in handlers, so `tools/call`
    still runs those tools.

!!! note
    A custom handler can only serve a method the SDK knows. The SDK rejects any other method as unknown before
    a handler runs. To add a new method, [write an extension](extensions.md#writing-your-own-extension) that
    registers its message classes.

## Request Handlers

Handle JSON-RPC requests (messages with an `id` that expect a response). Request handlers **must** return either a
`Response` or an `Error` object.

Attach request handlers with `addRequestHandler()` (single) or `addRequestHandlers()` (multiple). You can call these
methods as many times as needed; handlers are collected in call order, and the whole custom list runs before the
built-in defaults:

```php
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Server;

$server = Server::builder()
    // the SDK detects capabilities from registered elements, so declare what your handlers serve
    ->setCapabilities(new ServerCapabilities(tools: true, resources: false, prompts: true))
    ->addRequestHandler(new CustomListToolsHandler())
    ->addRequestHandlers([
        new CustomCallToolHandler(),
        new CustomGetPromptHandler(),
    ])
    ->build();
```

Request handlers implement `Mcp\Server\Handler\Request\RequestHandlerInterface`:

```php
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Session\SessionInterface;

interface RequestHandlerInterface
{
    public function supports(Request $request): bool;

    public function handle(Request $request, SessionInterface $session): Response|Error;
}
```

- `supports()` decides if the handler should process the incoming request
- `handle()` **must** return a `Response` (on success) or an `Error` (on failure)

## Notification Handlers

Handle JSON-RPC notifications (messages without an `id` that don't expect a response). Notification handlers **do not**
return anything - they perform side effects only.

Attach notification handlers with `addNotificationHandler()` (single) or `addNotificationHandlers()` (multiple):

```php
use Mcp\Server\Server;

// Handlers are your own classes implementing NotificationHandlerInterface;
// the SDK ships only its internal ones.
$server = Server::builder()
    ->addNotificationHandler(new AuditNotificationHandler($auditLog))
    ->addNotificationHandlers([
        new MetricsNotificationHandler($metrics),
        new CancellationNotificationHandler(),
    ])
    ->build();
```

Notification handlers implement `Mcp\Server\Handler\Notification\NotificationHandlerInterface`:

```php
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Session\SessionInterface;

interface NotificationHandlerInterface
{
    public function supports(Notification $notification): bool;

    public function handle(Notification $notification, SessionInterface $session): void;
}
```

- `supports()` decides if the handler should process the incoming notification
- `handle()` performs side effects but **does not** return a value (notifications have no response)

Request handlers stop at the first match. Notification handlers don't: every handler whose `supports()` returns
`true` runs, the built-in ones included.

## Protocol `2026-07-28`

Custom request handlers also serve requests on revision `2026-07-28`. Each of these requests gets a new
in-memory session, so nothing you store in `$session` reaches the next request. The same applies to handshake-era
requests over HTTP without `setSession()`. Notification handlers never run on this revision.

There are two exceptions. The SDK rejects the methods this revision removed (`initialize`, `ping`,
`logging/setLevel`, `resources/subscribe` and `resources/unsubscribe`) before any handler runs. And the SDK answers
`subscriptions/listen` itself, so your handlers never see it. `server/discover` is answered by the SDK's
`DiscoverHandler`, which a handler of your own can replace, see
[Customizing `server/discover`](../run/protocol-eras.md#customizing-serverdiscover).

## Key Differences

| Handler Type | Interface | Returns | Use Case |
|-------------|-----------|---------|----------|
| Request Handler | `RequestHandlerInterface` | `Response\|Error` | Handle requests that need responses (e.g., `tools/list`, `tools/call`) |
| Notification Handler | `NotificationHandlerInterface` | `void` | Handle fire-and-forget notifications (e.g., `notifications/initialized`, `notifications/progress`) |

## Example

Check out [`examples/server/custom-method-handlers/server.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/custom-method-handlers/server.php) for a complete example showing how to implement
custom `tools/list` and `tools/call` request handlers independently of the registry.
