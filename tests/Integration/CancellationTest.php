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

use Mcp\Client\CancellationTokenInterface;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use PHPUnit\Framework\Attributes\TestDox;

final class CancellationTest extends IntegrationTestCase
{
    #[TestDox('cancelling a pending stdio call notifies the server and leaves the connection usable')]
    public function testCancelsPendingCall(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'mcp-cancel-');
        try {
            $client = $this->connect('cancellation', env: ['MCP_FIXTURE_LOG' => $log]);
            $token = new class implements CancellationTokenInterface {
                public bool $cancelled = false;

                public function isCancellationRequested(): bool
                {
                    return $this->cancelled;
                }
            };

            try {
                $client->callTool('slow', onProgress: static function () use ($token): void {
                    $token->cancelled = true;
                }, cancellation: $token);
                $this->fail('The pending request should have been cancelled.');
            } catch (RequestCancelledException) {
            }

            // The next response is a barrier: the server processes the cancellation
            // and its late reply before it can answer this request.
            $this->assertSame('quick', $client->callTool('fast')->content[0]->text ?? null);
            $events = $this->events($log);
            $this->assertSame(['call', 'cancelled', 'call'], array_column($events, 'event'));
            $this->assertSame($events[0]['id'], $events[1]['id']);
        } finally {
            unlink($log);
        }
    }

    #[TestDox('a per-call deadline cancels an unanswered request without breaking the connection')]
    public function testDeadline(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'mcp-cancel-');
        try {
            $client = $this->connect('cancellation', env: ['MCP_FIXTURE_LOG' => $log]);
            try {
                $client->callTool('slow', timeoutSeconds: 0.05);
                $this->fail('The pending request should have timed out.');
            } catch (TimeoutException) {
            }

            $this->assertSame('quick', $client->callTool('fast')->content[0]->text ?? null);
            $events = $this->events($log);
            $this->assertSame(['call', 'cancelled', 'call'], array_column($events, 'event'));
            $this->assertSame($events[0]['id'], $events[1]['id']);
        } finally {
            unlink($log);
        }
    }

    #[TestDox('a pre-cancelled call sends no request')]
    public function testPreCancelledCall(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'mcp-cancel-');
        try {
            $client = $this->connect('cancellation', env: ['MCP_FIXTURE_LOG' => $log]);
            $token = new class implements CancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return true;
                }
            };

            try {
                $client->callTool('slow', cancellation: $token);
                $this->fail('A pre-cancelled call must not be sent.');
            } catch (RequestCancelledException) {
            }

            $this->assertSame('quick', $client->callTool('fast')->content[0]->text ?? null);
            $events = $this->events($log);
            $this->assertSame(['call'], array_column($events, 'event'));
            $this->assertSame('fast', $events[0]['name']);
        } finally {
            unlink($log);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(string $log): array
    {
        return array_map(static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), file($log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES));
    }
}
