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

The SDK dispatches 4 broad event types at the protocol level, allowing you to observe and modify all server operations:

### RequestEvent

**Dispatched**: When any request is received from the client, before it's processed by handlers.

**Properties**:

- `getRequest(): Request` - The incoming request
- `setRequest(Request $request): void` - Modify the request before processing
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the request method

### ResponseEvent

**Dispatched**: When a successful response is ready to be sent to the client, after handler execution.
See [When response and error events are skipped](#when-response-and-error-events-are-skipped) for the exceptions.

**Properties**:

- `getResponse(): Response` - The response being sent
- `setResponse(Response $response): void` - Modify the response before sending
- `getRequest(): Request` - The original request
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the request method

### ErrorEvent

**Dispatched**: When an error occurs during request processing. The same exceptions as for `ResponseEvent` apply.

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

### When response and error events are skipped

In the handshake era, a handler that suspends gets no `ResponseEvent` and no `ErrorEvent`. A
handler suspends whenever it calls the `ClientGateway`: `progress()`, `log()`, `notify()`,
`elicit()` or `sample()`, for example. A handler that returns an `InputRequiredResult` suspends
too, because the SDK sends its asks to the client. The transport sends the final result of
such a handler without dispatching an event.

### Protocol `2026-07-28`

Requests on protocol version `2026-07-28` dispatch the same request, response and error events, with a few differences:

- `ResponseEvent` fires on every `InputRequiredResult` round, not only on the final result. Listeners that only care about completed calls need to check the result type.
- `getSession()` returns a new in-memory session for each request. Anything a listener stores there is gone by the next request.
- `NotificationEvent` is not dispatched, since this protocol version runs no notification handlers. This includes `notifications/cancelled` on stdio.
- `server/discover` and `subscriptions/listen` dispatch no events.
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
