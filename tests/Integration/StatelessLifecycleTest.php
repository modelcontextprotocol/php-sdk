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

use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Drives the `stateless-lifecycle` example over real HTTP.
 *
 * Not an Inspector snapshot test like the other examples: the Inspector CLI
 * opens with `initialize`, which this revision removed, so it cannot reach a
 * modern-lifecycle server at all. Until it can, the example is verified the way
 * a conforming client would actually use it — raw requests carrying their own
 * `_meta` and headers.
 */
class StatelessLifecycleTest extends TestCase
{
    private const SERVER = __DIR__.'/../../examples/server/stateless-lifecycle/server.php';

    private Process $server;
    private int $port;

    protected function setUp(): void
    {
        $this->port = 8600 + (getmypid() % 300);

        $this->server = new Process(['php', '-S', \sprintf('127.0.0.1:%d', $this->port), self::SERVER]);
        $this->server->start();

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1)) {
                return;
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The example server did not start: %s', $this->server->getErrorOutput()));
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    #[TestDox('server/discover reports the versions, capabilities, identity and caching hints')]
    public function testDiscover(): void
    {
        $result = $this->call('server/discover', [])['result'];

        $this->assertSame([ProtocolVersion::V2026_07_28->value], $result['supportedVersions']);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame('Stateless Lifecycle Demo', $result['_meta']['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertSame(3_600_000, $result['ttlMs']);
        $this->assertSame('public', $result['cacheScope']);
    }

    #[TestDox('a tool call needs no handshake before it')]
    public function testToolCallWithoutAHandshake(): void
    {
        $result = $this->call('tools/call', ['name' => 'get_weather', 'arguments' => ['city' => 'Munich']], name: 'get_weather')['result'];

        $this->assertStringContainsString('Munich', $result['content'][0]['text']);
        $this->assertSame('complete', $result['resultType']);
    }

    #[TestDox('initialize is gone, and says so')]
    public function testInitializeIsRefused(): void
    {
        $answer = $this->call('initialize', []);

        $this->assertSame(-32601, $answer['error']['code']);
    }

    #[TestDox('a request whose header contradicts its body is refused with -32020')]
    public function testHeaderMismatchIsRefused(): void
    {
        $answer = $this->call('tools/call', ['name' => 'get_weather', 'arguments' => []], name: 'something_else');

        $this->assertSame(-32020, $answer['error']['code']);
    }

    #[TestDox('an unsupported version comes back with the set to retry from')]
    public function testUnsupportedVersion(): void
    {
        $answer = $this->call('tools/list', [], version: '1900-01-01');

        $this->assertSame(-32022, $answer['error']['code']);
        $this->assertSame([ProtocolVersion::V2026_07_28->value], $answer['error']['data']['supported']);
    }

    #[TestDox('a multi round-trip tool asks, then completes on the retry')]
    public function testMultiRoundTrip(): void
    {
        $asked = $this->call('tools/call', ['name' => 'greet', 'arguments' => []], name: 'greet', capabilities: ['elicitation' => new \stdClass()])['result'];

        $this->assertSame('input_required', $asked['resultType']);
        $this->assertSame('elicitation/create', $asked['inputRequests']['who']['method']);
        $this->assertNotEmpty($asked['requestState']);

        // An interim result is not cacheable and carries no hints.
        $this->assertArrayNotHasKey('ttlMs', $asked);

        $done = $this->call('tools/call', [
            'name' => 'greet',
            'arguments' => [],
            'requestState' => $asked['requestState'],
            'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]],
        ], name: 'greet', capabilities: ['elicitation' => new \stdClass()])['result'];

        $this->assertSame('Hello, Ada!', $done['content'][0]['text']);
        $this->assertSame('complete', $done['resultType']);
    }

    #[TestDox('a tampered requestState is refused')]
    public function testTamperedRequestStateIsRefused(): void
    {
        $asked = $this->call('tools/call', ['name' => 'greet', 'arguments' => []], name: 'greet', capabilities: ['elicitation' => new \stdClass()])['result'];

        [$body] = explode('.', $asked['requestState']);

        $answer = $this->call('tools/call', [
            'name' => 'greet',
            'arguments' => [],
            'requestState' => $body.'.'.strtr(base64_encode('forged'), '+/', '-_'),
            'inputResponses' => ['who' => ['action' => 'accept', 'content' => ['name' => 'Mallory']]],
        ], name: 'greet', capabilities: ['elicitation' => new \stdClass()]);

        $this->assertSame(-32602, $answer['error']['code']);
    }

    #[TestDox('asking a client that declared no elicitation is refused with -32021')]
    public function testUndeclaredCapabilityIsRefused(): void
    {
        $answer = $this->call('tools/call', ['name' => 'greet', 'arguments' => []], name: 'greet');

        $this->assertSame(-32021, $answer['error']['code']);
        $this->assertArrayHasKey('elicitation', $answer['error']['data']['requiredCapabilities']);
    }

    #[TestDox('progress and log notifications arrive on the response stream, before the response')]
    public function testResponseStreamCarriesNotifications(): void
    {
        $frames = $this->stream('tools/call', ['name' => 'reindex', 'arguments' => ['steps' => 2]], name: 'reindex', meta: [
            'progressToken' => 'p1',
            'io.modelcontextprotocol/logLevel' => 'info',
        ]);

        $methods = array_map(static fn (array $frame): string => $frame['method'] ?? 'response', $frames);

        $this->assertSame([
            'notifications/message',
            'notifications/progress',
            'notifications/message',
            'notifications/progress',
            'response',
        ], $methods);

        $this->assertSame('p1', $frames[1]['params']['progressToken']);
        $this->assertSame('Reindexed 2 shards.', $frames[4]['result']['content'][0]['text']);
    }

    #[TestDox('a request naming no log level receives no log messages')]
    public function testLoggingIsSilentWithoutALevel(): void
    {
        $frames = $this->stream('tools/call', ['name' => 'reindex', 'arguments' => ['steps' => 2]], name: 'reindex', meta: [
            'progressToken' => 'p1',
        ]);

        $methods = array_map(static fn (array $frame): string => $frame['method'] ?? 'response', $frames);

        $this->assertNotContains('notifications/message', $methods);
        $this->assertContains('notifications/progress', $methods);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $capabilities
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, ?string $name = null, ?string $version = null, array $capabilities = []): array
    {
        $body = $this->body($method, $params, $version, $capabilities);

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $this->headers($method, $name, $version)),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $raw = file_get_contents($this->url(), false, $context);

        $this->assertIsString($raw, 'no response from the example server');

        return json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     *
     * @return list<array<string, mixed>>
     */
    private function stream(string $method, array $params, ?string $name = null, array $meta = []): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $this->headers($method, $name, null)),
            'content' => $this->body($method, $params, null, [], $meta),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);

        $handle = fopen($this->url(), 'r', false, $context);
        $this->assertIsResource($handle);

        $frames = [];
        while (false !== $line = fgets($handle)) {
            $line = trim($line);

            // SSE comments are keep-alives and carry no event data.
            if ('' === $line || str_starts_with($line, ':')) {
                continue;
            }

            if (str_starts_with($line, 'data: ')) {
                $frames[] = json_decode(substr($line, 6), true, flags: \JSON_THROW_ON_ERROR);
            }
        }

        fclose($handle);

        return $frames;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $meta
     */
    private function body(string $method, array $params, ?string $version, array $capabilities = [], array $meta = []): string
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => $version ?? ProtocolVersion::V2026_07_28->value,
            'io.modelcontextprotocol/clientCapabilities' => (object) $capabilities,
            'io.modelcontextprotocol/clientInfo' => ['name' => 'integration-test', 'version' => '1.0.0'],
            ...$meta,
        ];

        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    private function headers(string $method, ?string $name, ?string $version): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
            'MCP-Protocol-Version: '.($version ?? ProtocolVersion::V2026_07_28->value),
            'Mcp-Method: '.$method,
        ];

        if (null !== $name) {
            $headers[] = 'Mcp-Name: '.$name;
        }

        return $headers;
    }

    private function url(): string
    {
        return \sprintf('http://127.0.0.1:%d/', $this->port);
    }
}
