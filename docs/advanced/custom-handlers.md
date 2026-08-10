# Custom Message Handlers

**Low-level escape hatch.** Custom message handlers run before the SDK's built-in handlers and give you total control over
individual JSON-RPC messages. They do not receive the builder's registry, container, or discovery output unless you pass
those dependencies in yourself.

> **Warning**: Custom message handlers bypass discovery, manual capability registration, and container lookups (unless
> you explicitly pass them). Tools, resources, and prompts you register elsewhere will not show up unless your handler
> loads and executes them manually. Reach for this API only when you need that level of control and are comfortable
> taking on the additional plumbing.

## Request Handlers

Handle JSON-RPC requests (messages with an `id` that expect a response). Request handlers **must** return either a
`Response` or an `Error` object.

Attach request handlers with `addRequestHandler()` (single) or `addRequestHandlers()` (multiple). You can call these
methods as many times as needed; each call prepends the handlers so they execute before the defaults:

```php
$server = Server::builder()
    ->addRequestHandler(new CustomListToolsHandler())
    ->addRequestHandlers([
        new CustomCallToolHandler(),
        new CustomGetPromptHandler(),
    ])
    ->build();
```

Request handlers implement `RequestHandlerInterface`:

```php
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
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

Notification handlers implement `NotificationHandlerInterface`:

```php
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Session\SessionInterface;

interface NotificationHandlerInterface
{
    public function supports(Notification $notification): bool;

    public function handle(Notification $notification, SessionInterface $session): void;
}
```

- `supports()` decides if the handler should process the incoming notification
- `handle()` performs side effects but **does not** return a value (notifications have no response)

## Key Differences

| Handler Type | Interface | Returns | Use Case |
|-------------|-----------|---------|----------|
| Request Handler | `RequestHandlerInterface` | `Response\|Error` | Handle requests that need responses (e.g., `tools/list`, `tools/call`) |
| Notification Handler | `NotificationHandlerInterface` | `void` | Handle fire-and-forget notifications (e.g., `notifications/initialized`, `notifications/progress`) |

## Example

Check out `examples/server/custom-method-handlers/server.php` for a complete example showing how to implement
custom `tools/list` and `tools/call` request handlers independently of the registry.
