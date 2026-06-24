# Events

The MCP SDK provides a PSR-14 compatible event system that allows you to hook into the server's lifecycle. Events enable request/response modification, and other user-defined behaviors.

## Table of Contents

- [Setup](#setup)
- [Protocol Events](#protocol-events)
  - [RequestEvent](#requestevent)
  - [ResponseEvent](#responseevent)
  - [ErrorEvent](#errorevent)
  - [NotificationEvent](#notificationevent)
  - [OutgoingRequestEvent](#outgoingrequestevent)
  - [ClientResponseEvent](#clientresponseevent)
- [List Change Events](#list-change-events)

## Setup

Configure an event dispatcher when building your server:

```php
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

The SDK dispatches 6 broad event types at the protocol level, allowing you to observe and modify all server operations:

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
- `getRequest(): Request` - The original request (null for parse errors)
- `getThrowable(): ?\Throwable` - The exception that caused the error (if any)
- `getSession(): SessionInterface` - The current session

### NotificationEvent

**Dispatched**: When a notification is received from the client, before it's processed by handlers.

**Properties**:
- `getNotification(): Notification` - The incoming notification
- `setNotification(Notification $notification): void` - Modify the notification before processing
- `getSession(): SessionInterface` - The current session
- `getMethod(): string` - Convenience method to get the notification method

### OutgoingRequestEvent

**Dispatched**: When the server sends a request to the client (e.g. `elicitation/create`, `sampling/create`).

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
- `getId(): string|int` - The JSON-RPC message ID
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
