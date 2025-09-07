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

namespace Mcp\Tests\Server;

use Mcp\JsonRpc\MessageFactory;
use Mcp\Server\NotificationPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @author Aggelos Bellos <aggelosbellos7@gmail.com>
 */
class NotificationPublisherTest extends TestCase
{
    public function testOnEventCreatesNotification(): void
    {
        $expectedNotifications = [];
        $notificationPublisher = new NotificationPublisher(MessageFactory::make());

        foreach (NotificationPublisher::EVENTS_TO_NOTIFICATIONS as $eventClass => $notificationClass) {
            /** @var Event $event */
            $event = new $eventClass();
            $notificationPublisher->enqueue($event);
            $expectedNotifications[] = $notificationClass;
        }

        $flushedNotifications = $notificationPublisher->flush();

        $this->assertCount(\count($expectedNotifications), $flushedNotifications);

        foreach ($flushedNotifications as $index => $notification) {
            $this->assertInstanceOf($expectedNotifications[$index], $notification);
        }

        $this->assertEmpty($notificationPublisher->flush());
    }
}
