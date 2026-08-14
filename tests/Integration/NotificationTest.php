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
use Mcp\Server\Builder as ServerBuilder;
use Mcp\Server\RequestContext;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Notifications a server emits while a tool is still running.
 *
 * These travel the same queue as a response but carry no id and expect no
 * answer, so what they exercise is delivery ordering rather than correlation.
 */
final class NotificationTest extends IntegrationTestCase
{
    #[TestDox('progress notifications reach the callback passed to callTool()')]
    public function testProgressReachesTheCaller(): void
    {
        $client = $this->connect($this->serverWithReportingTool());

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
        // and the gateway drops the notification rather than sending one the
        // client could not correlate.
        $client = $this->connect($this->serverWithReportingTool());

        $result = $client->callTool('work');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('finished', $result->content[0]->text);
    }

    #[TestDox('log notifications reach a registered logging handler')]
    public function testLoggingReachesTheClient(): void
    {
        $logged = [];
        $client = $this->connect(
            $this->serverWithReportingTool(),
            $this->clientBuilder()->addNotificationHandler(new LoggingNotificationHandler(
                static function (LoggingMessageNotification $notification) use (&$logged): void {
                    $logged[] = [$notification->level, $notification->data];
                },
            )),
        );

        $client->callTool('work');

        $this->assertSame([[LoggingLevel::Info, 'starting work']], $logged);
    }

    private function serverWithReportingTool(): ServerBuilder
    {
        return $this->serverBuilder()->addTool(
            static function (RequestContext $context): string {
                $gateway = $context->getClientGateway();

                $gateway->log(LoggingLevel::Info, 'starting work');
                $gateway->progress(0.5, 1.0, 'halfway');
                $gateway->progress(1.0, 1.0, 'done');

                return 'finished';
            },
            name: 'work',
            description: 'Reports progress and logs while working.',
        );
    }
}
