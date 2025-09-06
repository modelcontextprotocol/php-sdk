<?php

declare(strict_types=1);

namespace Mcp\Server;

use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class NotificationPublisher implements EventSubscriberInterface
{
    /**
     * @var array<class-string<mixed>, class-string<Notification>>
     */
    private const EVENTS_TO_NOTIFICATIONS = [
        ResourceListChangedEvent::class => ResourceListChangedNotification::class,
        PromptListChangedEvent::class => PromptListChangedNotification::class,
        ToolListChangedEvent::class => ToolListChangedNotification::class,
    ];

    /** @var list<HasMethodInterface> */
    private array $queue = [];

    public function __construct(
        private readonly MessageFactory $factory,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return array_fill_keys(array_keys(self::EVENTS_TO_NOTIFICATIONS), 'onEvent');
    }

    public function onEvent(object $event): void
    {
        $eventClass = $event::class;
        if (!isset(self::EVENTS_TO_NOTIFICATIONS[$eventClass])) {
            return;
        }

        $notificationType = self::EVENTS_TO_NOTIFICATIONS[$eventClass];
        $notification = $this->factory->createByType($notificationType, []);

        $this->queue[] = $notification;
    }

    /**
     * Yield and clear queued notifications; Server will encode+send them.
     *
     * @return iterable<HasMethodInterface>
     */
    public function flush(): iterable
    {
        if (!$this->queue) {
            return [];
        }

        $out = $this->queue;
        $this->queue = [];

        return $out;
    }
}
