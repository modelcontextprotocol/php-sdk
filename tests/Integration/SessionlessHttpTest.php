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
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

/**
 * A handshake-era client talking to a server built with {@see \Mcp\Server\Builder::withoutSessions()},
 * served by `php -S`, which keeps nothing between requests.
 */
final class SessionlessHttpTest extends TestCase
{
    private const TIMEOUT = 5;

    private ?Process $server = null;
    private string $sessions;
    private int $port;

    protected function setUp(): void
    {
        $this->sessions = sys_get_temp_dir().'/mcp-integration-sessionless-'.getmypid();
        $this->port = ServerProcess::findFreePort();

        @mkdir($this->sessions);

        $this->server = new Process(
            [\PHP_BINARY, '-S', \sprintf('127.0.0.1:%d', $this->port), __DIR__.'/Fixture/http.php'],
            env: [
                'MCP_INTEGRATION_SESSIONS' => $this->sessions,
                'MCP_INTEGRATION_SESSIONLESS' => '1',
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

    protected function tearDown(): void
    {
        $this->server?->stop();

        foreach (glob($this->sessions.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->sessions);
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

        $this->assertSame([], glob($this->sessions.'/*') ?: []);
    }

    #[TestDox('initialize, tools/list and tools/call are answered without Mcp-Session-Id, GET and DELETE with 405')]
    public function testWireCarriesNoSessionId(): void
    {
        $initialize = $this->request('POST', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'raw-client', 'version' => '1.0.0'],
            ],
        ]);

        $this->assertSame(200, $initialize['status']);
        $this->assertArrayNotHasKey('mcp-session-id', $initialize['headers']);

        $list = $this->request('POST', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);

        $this->assertSame(200, $list['status']);
        $this->assertArrayNotHasKey('mcp-session-id', $list['headers']);
        $this->assertSame('echo', $list['body']['result']['tools'][0]['name']);

        $call = $this->request('POST', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['text' => 'hello']],
        ]);

        $this->assertSame(200, $call['status']);
        $this->assertArrayNotHasKey('mcp-session-id', $call['headers']);
        $this->assertSame('hello', $call['body']['result']['content'][0]['text']);

        $this->assertSame(405, $this->request('GET')['status']);
        $this->assertSame(405, $this->request('DELETE')['status']);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array{status: int, headers: array<string, list<string>>, body: array<string, mixed>}
     */
    private function request(string $method, ?array $body = null): array
    {
        $response = HttpClient::create()->request($method, \sprintf('http://127.0.0.1:%d/', $this->port), [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => ProtocolVersion::V2025_11_25->value,
            ],
            'body' => null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
            'timeout' => self::TIMEOUT,
        ]);

        $payload = $response->getContent(false);

        return [
            'status' => $response->getStatusCode(),
            'headers' => $response->getHeaders(false),
            'body' => '' === $payload ? [] : json_decode($payload, true, flags: \JSON_THROW_ON_ERROR),
        ];
    }
}
