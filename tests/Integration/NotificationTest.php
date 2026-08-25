<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Notification\LoggingMessageNotification;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Notifications a server emits while a tool is still running.
 *
 * These share the pipe with the response but carry no id, so what they exercise
 * is delivery ordering rather than correlation.
 *
 * @see Fixture/notification.php for the server under test
 */
final class NotificationTest extends IntegrationTestCase
{
    #[TestDox('progress notifications reach the callback passed to callTool()')]
    public function testProgressReachesTheCaller(): void
    {
        $client = $this->connect('notification');

        $updates = [];
        $result = $client->callTool('work', [], static function (float $progress, ?float $total, ?string $message) use (&$updates): void {
            $updates[] = [$progress, $total, $message];
        });

        $this->assertSame([[0.5, 1.0, 'halfway'], [1.0, 1.0, 'done']], $updates);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('finished', $result->content[0]->text);
    }

    #[TestDox('progress is skipped when the caller asked for none')]
    public function testProgressIsSkippedWithoutAToken(): void
    {
        // Without an onProgress callback the request carries no progress token,
        // so the gateway drops the notification instead of sending it.
        $client = $this->connect('notification');

        $result = $client->callTool('work');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('finished', $result->content[0]->text);
    }

    #[TestDox('log notifications reach a registered logging handler')]
    public function testLoggingReachesTheClient(): void
    {
        $logged = [];
        $client = $this->connect(
            'notification',
            $this->clientBuilder()->addNotificationHandler(new LoggingNotificationHandler(
                static function (LoggingMessageNotification $notification) use (&$logged): void {
                    $logged[] = [$notification->level, $notification->data];
                },
            )),
        );

        $client->callTool('work');

        $this->assertSame([[LoggingLevel::Info, 'starting work']], $logged);
    }
}
