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

`RequestEvent`, `ResponseEvent` and `ErrorEvent` fire on both the handshake era and the modern (`2026-07-28`) era.

On the modern era the session carried by these events is per-request and discarded after the response.

### RequestEvent

**Dispatched**: When any request is received from the client, before it's processed by handlers. On the modern era this includes a multi round-trip retry MRTR : if the client sent `inputResponses`, they are already lifted onto the session as `InputContext` (`$event->getSession()->get(InputContext::class)`). They are not on the typed `Request` (for example `CallToolRequest` only carries `name` and `arguments`).

**Properties**:
- `getRequest(): Request` - The incoming request
- `setRequest(Request $request): void` - Modify the request before processing
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the request method

### ResponseEvent

**Dispatched**: When a successful response is ready to be sent to the client, after handler execution.
Also dispatched when a suspended Fiber completes (e.g. after elicitation or sampling on a handshake connection).

On the modern era an elicitation is a successful result of the original method (`resultType: input_required`). Listen for `ResponseEvent` whose `$event->getResponse()->result` is an `InputRequiredResult`.

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

## Handshake era only, before MCP 2026-07-28

These events are dispatched only on handshake-era connections (protocol revisions before `2026-07-28`). The modern revision has no client-to-server notification handlers over HTTP, and no server-initiated JSON-RPC requests.

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

## List Change Events

These events are dispatched when the lists of available capabilities change:

| Event                              | Description                                                      |
|------------------------------------|------------------------------------------------------------------|
| `ToolListChangedEvent`             | Dispatched when the list of available tools changes              |
| `ResourceListChangedEvent`         | Dispatched when the list of available resources changes          |
| `ResourceTemplateListChangedEvent` | Dispatched when the list of available resource templates changes |
| `PromptListChangedEvent`           | Dispatched when the list of available prompts changes            |

These events carry no data and are used to notify clients that they should refresh their capability lists.

```php
use Mcp\Event\ToolListChangedEvent;

$dispatcher->addListener(ToolListChangedEvent::class, function (ToolListChangedEvent $event) {
    $logger->info('Tool list has changed, clients should refresh');
});
```
