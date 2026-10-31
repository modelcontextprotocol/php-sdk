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

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Tests\Support\ServerProcess;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A handshake-era client talking to a server built without {@see \Mcp\Server\Builder::setSession()},
 * served by `php -S`, which keeps nothing between requests.
 */
final class SessionlessHttpTest extends TestCase
{
    private const TIMEOUT = 5;

    private ?Process $server = null;
    private int $port;

    protected function setUp(): void
    {
        $this->port = ServerProcess::findFreePort();

        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $this->port), __DIR__.'/Fixture/http.php'],
            env: ['MCP_INTEGRATION_SESSIONLESS' => '1'],
        );
        $this->server->start();

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The fixture server did not start: %s', $this->server->getErrorOutput()));
    }

    protected function tearDown(): void
    {
        ServerProcess::stop($this->server);
    }

    #[TestDox('a handshake-era client initializes, lists and calls tools without a session')]
    public function testClientTalksWithoutASession(): void
    {
        $transport = new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port));

        $client = Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(self::TIMEOUT)
            ->setRequestTimeout(self::TIMEOUT)
            ->build();

        $client->connect($transport);

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
        $this->assertNull($transport->getSessionId());

        $this->assertSame(['echo'], array_map(static fn ($tool): string => $tool->name, $client->listTools()->tools));

        $result = $client->callTool('echo', ['text' => 'hello']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('hello', $result->content[0]->text);

        $client->disconnect();
    }
}
