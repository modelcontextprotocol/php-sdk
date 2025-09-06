<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @author Aggelos Bellos <aggelosbellos7@gmail.com>
 */
class NotificationPublisher
{
    /**
     * @var array<class-string<Event>, class-string<Notification>>
     */
    public const EVENTS_TO_NOTIFICATIONS = [
        ResourceListChangedEvent::class => ResourceListChangedNotification::class,
        PromptListChangedEvent::class => PromptListChangedNotification::class,
        ToolListChangedEvent::class => ToolListChangedNotification::class,
    ];

    /** @var list<Notification> */
    private array $queue = [];

    public function __construct(
        private readonly MessageFactory $factory,
    ) {
    }

    public static function make(EventDispatcher $eventDispatcher): self
    {
        $instance = new self(MessageFactory::make());

        $eventDispatcher->addListener(Event::class, [$instance, 'onEvent']);

        return $instance;
    }

    public function onEvent(Event $event): void
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
     * @return iterable<Notification>
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
