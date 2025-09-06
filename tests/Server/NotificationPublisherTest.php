<?php

declare(strict_types=1);

namespace Mcp\Tests\Server;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Server\NotificationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

class NotificationPublisherTest extends TestCase
{
    public function testOnEventCreatesNotification(): void
    {
        $expectedNotifications = [];
        $notificationPublisher = new NotificationPublisher(MessageFactory::make());

        foreach (NotificationPublisher::EVENTS_TO_NOTIFICATIONS as $eventClass => $notificationClass) {
            /** @var Event $event */
            $event = new $eventClass();
            $notificationPublisher->onEvent($event);
            $expectedNotifications[] = $notificationClass;
        }

        $flushedNotifications = $notificationPublisher->flush();

        $this->assertCount(count($expectedNotifications), $flushedNotifications);

        foreach ($flushedNotifications as $index => $notification) {
            $this->assertInstanceOf($expectedNotifications[$index], $notification);
        }

        $this->assertEmpty($notificationPublisher->flush());
    }
}