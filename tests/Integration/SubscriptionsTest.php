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
use Mcp\Client\Client;
use Mcp\Client\Exception\RequestCancelledException;
use Mcp\Client\Exception\RuntimeException;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\SubscriptionFilter;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Process\Process;

/**
 * The SDK's client listening on the SDK's server: `subscriptions/listen` on 2026-07-28.
 */
class SubscriptionsTest extends TestCase
{
    private Process $server;
    private int $port;
    private string $busDirectory;
    private Psr16NotificationBus $bus;

    protected function setUp(): void
    {
        $this->busDirectory = sys_get_temp_dir().'/mcp-subscriptions-'.bin2hex(random_bytes(4));
        $this->bus = new Psr16NotificationBus(new Psr16Cache(new FilesystemAdapter('bus', 0, $this->busDirectory)));
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            ServerProcess::stop($this->server);
        }

        if (is_dir($this->busDirectory)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->busDirectory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->busDirectory);
        }
    }

    #[TestDox('delivers only the subscribed resource updates, after the acknowledgment, until cancelled')]
    public function testListenDeliversSubscribedUpdates(): void
    {
        $client = $this->connect();
        $cancellation = new class implements CancellationTokenInterface {
            public bool $requested = false;

            public function isCancellationRequested(): bool
            {
                return $this->requested;
            }
        };

        $agreed = null;
        $received = [];

        try {
            $client->listen(
                new SubscriptionFilter(resourcesListChanged: true, resourceSubscriptions: ['file:///watched.txt']),
                static function (Notification $notification) use (&$received, $cancellation): void {
                    $received[] = $notification;
                    $cancellation->requested = true;
                },
                function (SubscriptionFilter $filter) use (&$agreed): void {
                    $agreed = $filter;
                    $this->bus->publish(new ResourceUpdatedNotification('file:///other.txt'));
                    $this->bus->publish(new ResourceListChangedNotification());
                    $this->bus->publish(new ResourceUpdatedNotification('file:///watched.txt'));
                },
                $cancellation,
            );
            $this->fail('Listening should end with the cancellation.');
        } catch (RequestCancelledException) {
        }

        $this->assertEquals(new SubscriptionFilter(resourceSubscriptions: ['file:///watched.txt']), $agreed, 'The server announces no resource list changes, so it agrees to the subscription only.');
        $this->assertCount(1, $received);
        $this->assertInstanceOf(ResourceUpdatedNotification::class, $received[0]);
        $this->assertSame('file:///watched.txt', $received[0]->uri);

        // The connection stays usable once the stream is closed.
        $client->ping();
    }

    #[TestDox('returns when the server ends the subscription gracefully')]
    public function testListenReturnsWhenTheServerEndsTheSubscription(): void
    {
        $client = $this->connect(['MCP_INTEGRATION_LIFETIME' => '1']);
        $acknowledged = false;

        $started = microtime(true);
        $client->listen(
            new SubscriptionFilter(resourceSubscriptions: ['file:///watched.txt']),
            static fn (Notification $notification) => null,
            static function () use (&$acknowledged): void {
                $acknowledged = true;
            },
        );

        $this->assertTrue($acknowledged);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
    }

    #[TestDox('returns at once when the server agrees to deliver nothing')]
    public function testListenEndsAtOnceWhenNothingIsAgreed(): void
    {
        $client = $this->connect();
        $agreed = null;

        $client->listen(
            new SubscriptionFilter(toolsListChanged: true),
            static fn (Notification $notification) => null,
            static function (SubscriptionFilter $filter) use (&$agreed): void {
                $agreed = $filter;
            },
        );

        $this->assertNotNull($agreed);
        $this->assertTrue($agreed->isEmpty());
    }

    #[TestDox('refuses to listen on a handshake-era connection')]
    public function testListenNeedsTheModernEra(): void
    {
        $client = $this->connect(version: ProtocolVersion::V2025_11_25);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs protocol revision 2026-07-28');

        $client->listen(new SubscriptionFilter(resourceSubscriptions: ['file:///watched.txt']), static fn () => null);
    }

    /**
     * @param array<string, string> $env
     */
    private function connect(array $env = [], ProtocolVersion $version = ProtocolVersion::V2026_07_28): Client
    {
        $this->port = 9200 + (getmypid() % 300);
        // The stream holds one worker while the next requests need another.
        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $this->port), __DIR__.'/Fixture/subscriptions.php'],
            env: ['PHP_CLI_SERVER_WORKERS' => '4', 'MCP_INTEGRATION_BUS' => $this->busDirectory, ...$env],
        );
        $this->server->start();

        $deadline = microtime(true) + 5;
        while (!@fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1)) {
            if (microtime(true) > $deadline) {
                $this->fail(\sprintf('The fixture server did not start: %s', $this->server->getErrorOutput()));
            }
            usleep(50_000);
        }

        $client = Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setProtocolVersion($version)
            ->setRequestTimeout(5)
            ->build();
        $client->connect(new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port)));

        return $client;
    }
}
