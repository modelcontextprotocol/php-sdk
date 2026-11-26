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
use Mcp\Client\Exception\TimeoutException;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A tool whose arguments go into Mcp-Param-* headers, called without listing the tools first.
 *
 * @author Diego D'amico
 */
final class ToolHeaderRetryTest extends TestCase
{
    private const TIMEOUT = 5;

    private ?Process $server = null;
    private int $port;
    private string $log;

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'mcp-tool-headers-');
    }

    protected function tearDown(): void
    {
        ServerProcess::stop($this->server);
        @unlink($this->log);
    }

    public function testACallRejectedForMissingHeadersIsRetriedAfterListingTheTools(): void
    {
        $client = $this->connect();

        $result = $client->callTool('list_issues', ['owner' => 'odiseoteam', 'repo' => 'reflex']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('odiseoteam/reflex', $result->content[0]->text);
        $this->assertSame(2, $this->listings(), 'Both pages of tools are listed before the retry.');

        $client->disconnect();
    }

    public function testTheListingAndTheRetryShareTheCallsTimeout(): void
    {
        // Each page takes 0.5 s and the tool 0.8 s: listing leaves less than the tool needs.
        $client = $this->connect(['MCP_FIXTURE_LIST_DELAY' => '0.5', 'MCP_FIXTURE_TOOL_DELAY' => '0.8']);

        $started = microtime(true);
        try {
            $client->callTool('list_issues', ['owner' => 'odiseoteam', 'repo' => 'reflex'], timeoutSeconds: 1.5);
            $this->fail('The retry should run out of the time the listing left.');
        } catch (TimeoutException) {
        }

        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function testACancellationDuringTheListingStopsIt(): void
    {
        $client = $this->connect(['MCP_FIXTURE_LIST_DELAY' => '0.5']);
        $cancellation = new class(microtime(true) + 0.2) implements CancellationTokenInterface {
            public function __construct(private readonly float $at)
            {
            }

            public function isCancellationRequested(): bool
            {
                return microtime(true) >= $this->at;
            }
        };

        try {
            $client->callTool('list_issues', ['owner' => 'odiseoteam', 'repo' => 'reflex'], cancellation: $cancellation);
            $this->fail('The cancellation should stop the call.');
        } catch (RequestCancelledException) {
        }

        $this->assertSame(1, $this->listings(), 'The second page is not requested once cancelled.');
    }

    /**
     * @param array<string, string> $env
     */
    private function connect(array $env = []): Client
    {
        $this->port = ServerProcess::findFreePort();

        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $this->port), __DIR__.'/Fixture/tool_headers.php'],
            env: ['MCP_FIXTURE_LOG' => $this->log, ...$env],
        );
        $this->server->start();

        $deadline = microtime(true) + 5;

        while (!@fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1)) {
            if (microtime(true) >= $deadline) {
                $this->fail(\sprintf('The fixture server did not start: %s', $this->server->getErrorOutput()));
            }

            usleep(50_000);
        }

        $client = Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setInitTimeout(self::TIMEOUT)
            ->setRequestTimeout(self::TIMEOUT)
            ->build();
        $client->connect(new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port)));

        return $client;
    }

    private function listings(): int
    {
        return \count(file($this->log, \FILE_SKIP_EMPTY_LINES) ?: []);
    }
}
