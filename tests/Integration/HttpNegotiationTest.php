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
use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\ConnectionException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * What a client and a server settle on over Streamable HTTP, for every pairing
 * of what each end speaks.
 *
 * {@see HandshakeTest} covers the same ground over stdio, where the era is
 * settled once per process; here it is a property of the endpoint, and a
 * server from before the modern era answers the probe with an HTTP refusal.
 *
 * @see Fixture/http.php for the server under test
 */
final class HttpNegotiationTest extends TestCase
{
    private const TIMEOUT = 5;

    private const BOTH = 'both';
    private const HANDSHAKE_ONLY = 'handshake-only';
    private const MODERN_ONLY = 'modern-only';

    private ?Process $server = null;
    private string $sessions;
    private int $port;

    protected function setUp(): void
    {
        $this->sessions = sys_get_temp_dir().'/mcp-integration-sessions-'.getmypid();
        $this->port = 9600 + (getmypid() % 200);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();

        foreach (glob($this->sessions.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->sessions);
    }

    /**
     * @return iterable<string, array{string, ?ProtocolVersion, ProtocolVersion}>
     */
    public static function provideNegotiations(): iterable
    {
        yield 'a client and a server speaking both eras' => [self::BOTH, null, ProtocolVersion::V2026_07_28];
        yield 'a handshake-era client and a server speaking both eras' => [self::BOTH, ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25];
        yield 'a client speaking both eras and a server without the modern era' => [self::HANDSHAKE_ONLY, null, ProtocolVersion::V2025_11_25];
        yield 'a handshake-era client and a server without the modern era' => [self::HANDSHAKE_ONLY, ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18];
        yield 'a client speaking both eras and a server with only the modern era' => [self::MODERN_ONLY, null, ProtocolVersion::V2026_07_28];
    }

    #[DataProvider('provideNegotiations')]
    #[TestDox('$_dataName settle on a revision and talk')]
    public function testNegotiatesAndTalks(string $server, ?ProtocolVersion $clientVersion, ProtocolVersion $expected): void
    {
        $this->start($server);

        $builder = $this->clientBuilder();

        if (null !== $clientVersion) {
            $builder->setProtocolVersion($clientVersion);
        }

        $client = $builder->build();
        $started = microtime(true);
        $client->connect($this->transport());

        // A refused probe is answered at once; only silence would cost the timeout.
        $this->assertLessThan(self::TIMEOUT - 1, microtime(true) - $started);
        $this->assertSame($expected, $client->getProtocolVersion());
        $this->assertSame('integration-server', $client->getServerInfo()?->name);
        $this->assertSame('Be brief.', $client->getInstructions());

        $result = $client->callTool('echo', ['text' => 'hello']);

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('hello', $result->content[0]->text);

        $client->disconnect();
    }

    #[TestDox('a handshake-era client is told which revisions a server with only the modern era speaks')]
    public function testHandshakeClientLearnsWhatAModernOnlyServerSpeaks(): void
    {
        $this->start(self::MODERN_ONLY);

        $client = $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2025_11_25)->setMaxRetries(0)->build();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('it supports 2026-07-28');

        $client->connect($this->transport());
    }

    #[TestDox('a modern-only client refuses a server without the modern era')]
    public function testModernOnlyClientRefusesAHandshakeOnlyServer(): void
    {
        $this->start(self::HANDSHAKE_ONLY);

        $client = $this->clientBuilder()->setFallbackProtocolVersion(null)->setMaxRetries(0)->build();

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('without a handshake fallback');

        $client->connect($this->transport());
    }

    private function clientBuilder(): ClientBuilder
    {
        return Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setInitTimeout(self::TIMEOUT)
            ->setRequestTimeout(self::TIMEOUT);
    }

    private function transport(): HttpTransport
    {
        return new HttpTransport(\sprintf('http://127.0.0.1:%d/', $this->port));
    }

    private function start(string $server): void
    {
        @mkdir($this->sessions);

        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $this->port), __DIR__.'/Fixture/http.php'],
            env: [
                'MCP_INTEGRATION_SESSIONS' => $this->sessions,
                'MCP_INTEGRATION_HANDSHAKE_ONLY' => self::HANDSHAKE_ONLY === $server ? '1' : '',
                'MCP_INTEGRATION_MODERN_ONLY' => self::MODERN_ONLY === $server ? '1' : '',
            ],
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
}
