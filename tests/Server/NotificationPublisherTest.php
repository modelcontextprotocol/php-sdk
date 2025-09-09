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

use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\NotificationPublisher;
use PHPUnit\Framework\TestCase;

/**
 * @author Aggelos Bellos <aggelosbellos7@gmail.com>
 */
class NotificationPublisherTest extends TestCase
{
    public function testEnqueue(): void
    {
        $expectedNotifications = [
            new ToolListChangedNotification(),
            new ResourceListChangedNotification(),
            new PromptListChangedNotification(),
        ];
        $notificationPublisher = new NotificationPublisher();

        foreach ($expectedNotifications as $notificationType) {
            $notificationPublisher->enqueue($notificationType);
        }

        $flushedNotifications = iterator_to_array($notificationPublisher->flush());

        $this->assertCount(\count($expectedNotifications), $flushedNotifications);

        foreach ($flushedNotifications as $index => $notification) {
            $this->assertSame($expectedNotifications[$index], $notification);
        }

        $this->assertEmpty(iterator_to_array($notificationPublisher->flush()));
    }
}
