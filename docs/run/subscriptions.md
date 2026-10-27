# Subscriptions

> `subscriptions/listen` belongs to protocol revision `2026-07-28`; see
> [Protocol versions](../protocol-versions.md). Handshake-era clients use `resources/subscribe`, which the
> same server still answers. The notification bus below doesn't reach them, and the server doesn't send
> them change notifications by itself. Over HTTP, a notification queued for them goes out with the
> response to their next `POST` request, and an HTTP `GET` is answered with `405`.

`subscriptions/listen` replaces the HTTP `GET` stream and `resources/subscribe`. The client
opens a long-lived POST whose response stream carries the notification types it asked for;
the server acknowledges first with `notifications/subscriptions/acknowledged`, reporting the
subset it agreed to honour.

## The notification bus

Delivery needs a bus, because the process that publishes and the process holding the stream
open are often not the same one:

```php
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\Psr16NotificationBus;

// stdio, or a persistent runtime where the whole server is one process
->setNotificationBus(new InMemoryNotificationBus())

// PHP-FPM: the publisher and the stream are different workers
->setNotificationBus(new Psr16NotificationBus($cache))
```

Registry changes (`registerTool()`, `unregisterPrompt()`, …) are published automatically, with or
without an event dispatcher, unless you pass your own registry with `setRegistry()`. Then you wire
it to the bus yourself, and the builder announces `listChanged` for it:

```php
use Mcp\Capability\Registry;
use Mcp\Server\Subscription\PublishingEventDispatcher;

$bus = new InMemoryNotificationBus();
$registry = new Registry(new PublishingEventDispatcher($bus));

$server = Server::builder()
    ->setRegistry($registry)
    ->setNotificationBus($bus)
    ->build();
```

Anything else — `notifications/resources/updated` above all — is published by the
application:

```php
use Mcp\Schema\Notification\ResourceUpdatedNotification;

$bus->publish(new ResourceUpdatedNotification('file:///project/config.json'));
```

## How long a stream lives

`Builder::setSubscriptionLifetime()` bounds how long a stream is held before the server
closes it gracefully. The real ceiling is the runtime's: under PHP-FPM a stream cannot
outlive `max_execution_time`. Pass `0` for "until the client or the runtime ends it".

A stream that can carry nothing, because the server announces none of the types the client
asked for, or the client asked for none, is acknowledged and closed right away.

Over stdio the lifetime does not apply: a stream stays open until the client sends
`notifications/cancelled` for it, see [Over stdio](protocol-eras.md#over-stdio).
