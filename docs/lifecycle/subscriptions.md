# Subscriptions

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

Registry changes (`registerTool()`, `unregisterPrompt()`, …) are published automatically.
Anything else — `notifications/resources/updated` above all — is published by the
application:

```php
$bus->publish(new ResourceUpdatedNotification('file:///project/config.json'));
```

## How long a stream lives

`Builder::setSubscriptionLifetime()` bounds how long a stream is held before the server
closes it gracefully. The real ceiling is the runtime's: under PHP-FPM a stream cannot
outlive `max_execution_time`. Pass `0` for "until the client or the runtime ends it".
