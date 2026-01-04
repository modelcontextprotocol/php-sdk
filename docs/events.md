# Events

The MCP SDK provides a PSR-14 compatible event system that allows you to hook into the server's lifecycle. Events enable logging, monitoring, validation, caching, and other custom behaviors.

## Table of Contents

- [Setup](#setup)
- [List Change Events](#list-change-events)
- [Lifecycle Events](#lifecycle-events)
  - [Tool Events](#tool-events)
  - [Prompt Events](#prompt-events)
  - [Resource Events](#resource-events)
- [Server Events](#server-events)
- [Use Cases](#use-cases)

## Setup

Configure an event dispatcher when building your server:

```php
use Mcp\Server;
use Symfony\Component\EventDispatcher\EventDispatcher;

$dispatcher = new EventDispatcher();

// Register your listeners
$dispatcher->addListener(CallToolResultEvent::class, function (CallToolResultEvent $event) {
    // Handle event
});

$server = Server::builder()
    ->setEventDispatcher($dispatcher)
    ->build();
```

## List Change Events

These events are dispatched when the lists of available capabilities change:

| Event | Description |
|-------|-------------|
| `ToolListChangedEvent` | Dispatched when the list of available tools changes |
| `ResourceListChangedEvent` | Dispatched when the list of available resources changes |
| `ResourceTemplateListChangedEvent` | Dispatched when the list of available resource templates changes |
| `PromptListChangedEvent` | Dispatched when the list of available prompts changes |

These events carry no data and are used to notify clients that they should refresh their capability lists.

```php
use Mcp\Event\ToolListChangedEvent;

$dispatcher->addListener(ToolListChangedEvent::class, function (ToolListChangedEvent $event) {
    $logger->info('Tool list has changed, clients should refresh');
});
```

## Lifecycle Events

### Tool Events

Events for the tool call lifecycle:

| Event | Timing                     | Data |
|-------|----------------------------|------|
| `CallToolRequestEvent` | Before tool execution      | `request` |
| `CallToolResultEvent` | After successful execution | `request`, `result` |
| `CallToolExceptionEvent` | On uncaught exception      | `request`, `throwable` |

### Prompt Events

Events for the prompt retrieval lifecycle:

| Event | Timing                     | Data |
|-------|----------------------------|------|
| `GetPromptRequestEvent` | Before prompt execution    | `request` |
| `GetPromptResultEvent` | After successful execution | `request`, `result` |
| `GetPromptExceptionEvent` | On uncaught exception      | `request`, `throwable` |

### Resource Events

Events for the resource read lifecycle:

| Event | Timing                | Data |
|-------|-----------------------|------|
| `ReadResourceRequestEvent` | Before resource read  | `request` |
| `ReadResourceResultEvent` | After successful read | `request`, `result` |
| `ReadResourceExceptionEvent` | On uncaught exception | `request`, `throwable` |

## Server Events

| Event | Timing | Data |
|-------|--------|------|
| `InitializeRequestEvent` | When client sends initialize request | `request` (InitializeRequest) |
| `PingRequestEvent` | When client sends ping request | `request` (PingRequest) |
