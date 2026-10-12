# Events

The MCP SDK provides a PSR-14 compatible event system that allows you to hook into the server's lifecycle. Events enable request/response modification, and other user-defined behaviors.

## Setup

Configure an event dispatcher when building your server:

```php
use Mcp\Event\RequestEvent;
use Mcp\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();

// Register your listeners
$dispatcher->addListener(RequestEvent::class, function (RequestEvent $event) {
    // Handle any incoming request
    if ($event->getMethod() === 'tools/call') {
        // Handle tool call requests specifically
    }
});

$server = Server::builder()
    ->setEventDispatcher($dispatcher)
    ->build();
```

## Protocol Events

The SDK dispatches 6 broad event types at the protocol level. `RequestEvent`, `ResponseEvent`, `ErrorEvent` and `NotificationEvent` allow you to modify the message, while `ServerRequestEvent` and `ClientResponseEvent` are read-only and only let you observe it:

### RequestEvent

**Dispatched**: When any request is received from the client, before it's processed by handlers.

**Properties**:

- `getRequest(): Request` - The incoming request
- `setRequest(Request $request): void` - Modify the request before processing
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the request method

### ResponseEvent

**Dispatched**: When a successful response is ready to be sent to the client, after handler execution. Also dispatched when a suspended Fiber completes (e.g. after elicitation or sampling).

**Properties**:

- `getResponse(): Response` - The response being sent
- `setResponse(Response $response): void` - Modify the response before sending
- `getRequest(): Request` - The original request
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the request method

### ErrorEvent

**Dispatched**: When an error occurs during request processing. Also dispatched when a suspended Fiber completes with an error.

**Properties**:

- `getError(): Error` - The error being sent
- `setError(Error $error): void` - Modify the error before sending
- `getRequest(): Request` - The original request. Messages that fail to parse are rejected before this event, so a listener never sees them.
- `getThrowable(): ?\Throwable` - The exception that caused the error (if any)
- `getSession(): SessionInterface` - The current session

### NotificationEvent

**Dispatched**: When a notification is received from the client, before it's processed by handlers.

**Properties**:

- `getNotification(): Notification` - The incoming notification
- `setNotification(Notification $notification): void` - Modify the notification before processing
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the notification method

### ServerRequestEvent

**Dispatched**: When the server sends a request to the client (e.g. `elicitation/create`, `sampling/createMessage`).

**Properties**:

- `getRequest(): Request` - The outgoing request (with server-assigned ID)
- `getSession(): SessionInterface` - The current session
- `getTimeout(): int` - Maximum time to wait for the client response (seconds)
- `getMethod(): string` - Convenience method to get the request method

### ClientResponseEvent

**Dispatched**: When the server receives a client response to a prior outgoing request.

**Properties**:

- `getResponse(): Response|Error` - The client's reply
- `getSession(): SessionInterface` - The current session
- `getId(): string|int|null` - The JSON-RPC message ID
- `isError(): bool` - Whether the client returned a JSON-RPC error

### Protocol `2026-07-28`

Requests on protocol version `2026-07-28` dispatch the same request, response and error events, with a few differences:

- `ResponseEvent` fires on every `InputRequiredResult` round, not only on the final result. Listeners that only care about completed calls need to check the result type.
- `getSession()` returns a new in-memory session for each request. Anything a listener stores there is gone by the next request.
- `NotificationEvent` is not dispatched, since this protocol version runs no notification handlers. This includes `notifications/cancelled` on stdio.
- `server/discover` and `subscriptions/listen` dispatch no events.
- `ServerRequestEvent` and `ClientResponseEvent` are not dispatched: the server asks for input with an `InputRequiredResult`, which `ResponseEvent` carries, and the client answers with `inputResponses` on the retried request.
- Requests the SDK rejects before a handler runs dispatch no events. Examples are parse errors, a missing `_meta`, a protocol version mismatch, a removed method or a `requestState` that fails verification.

## List Change Events

These events are dispatched when the lists of available capabilities change:

| Event                              | Description                                                      |
|------------------------------------|------------------------------------------------------------------|
| `ToolListChangedEvent`             | Dispatched when the list of available tools changes              |
| `ResourceListChangedEvent`         | Dispatched when the list of available resources changes          |
| `ResourceTemplateListChangedEvent` | Dispatched when the list of available resource templates changes |
| `PromptListChangedEvent`           | Dispatched when the list of available prompts changes            |

These events carry no data. The registry dispatches them when you add or remove a tool, resource, resource
template or prompt at runtime. They are not dispatched while the registry loads its initial elements.

The SDK does not send these changes to handshake-era clients. To send them to clients, configure a notification
bus: the SDK then publishes each change to clients listening with `subscriptions/listen`. See
[Subscriptions](../run/subscriptions.md).

To react to a change yourself, add a listener. This example uses `symfony/event-dispatcher`, one PSR-14
implementation you can install with `composer require symfony/event-dispatcher`:

```php
use Mcp\Event\ToolListChangedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** @var LoggerInterface $logger */
$dispatcher = new EventDispatcher();
$dispatcher->addListener(ToolListChangedEvent::class, function (ToolListChangedEvent $event) use ($logger) {
    $logger->info('The tool list has changed.');
});
```
