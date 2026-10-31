<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Stateless\RequestMeta;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Handshake-era traffic of a server built with {@see Server\Builder::withoutSessions()}.
 *
 * Each request runs on a server of its own, as it would on a PHP worker behind a load
 * balancer, and only the session directory is shared, to show that nothing is stored.
 */
final class SessionlessHttpTest extends TestCase
{
    private Psr17Factory $factory;
    private string $sessions;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->sessions = sys_get_temp_dir().'/mcp-sessionless-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sessions.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->sessions);
    }

    #[TestDox('initialize is answered without a session id, and nothing is stored')]
    public function testInitializeIsAnsweredWithoutASessionId(): void
    {
        $response = $this->send('POST', $this->initialize());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));
        $this->assertSame(ProtocolVersion::V2025_11_25->value, self::decode($response)['result']['protocolVersion']);
        $this->assertSame([], $this->storedSessions());
    }

    #[TestDox('requests after initialize are served without a session id')]
    public function testRequestsAreServedWithoutASessionId(): void
    {
        $this->send('POST', $this->initialize());

        $list = $this->send('POST', $this->request('tools/list'));
        $call = $this->send('POST', $this->request('tools/call', ['name' => 'echo', 'arguments' => ['text' => 'hello']]));

        $this->assertSame(200, $list->getStatusCode());
        $this->assertContains('echo', array_column(self::decode($list)['result']['tools'], 'name'));
        $this->assertSame(200, $call->getStatusCode());
        $this->assertSame('hello', self::decode($call)['result']['content'][0]['text']);
        $this->assertFalse($list->hasHeader(StreamableHttpTransport::SESSION_HEADER));
        $this->assertFalse($call->hasHeader(StreamableHttpTransport::SESSION_HEADER));
        $this->assertSame([], $this->storedSessions());
    }

    #[TestDox('a session id sent by the client is ignored instead of refused')]
    public function testSessionIdOfTheClientIsIgnored(): void
    {
        $response = $this->send('POST', $this->request('tools/list'), [StreamableHttpTransport::SESSION_HEADER => 'f0e1d2c3-b4a5-4697-8879-6a5b4c3d2e1f']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('tools', self::decode($response)['result']);
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));
    }

    #[TestDox('GET and DELETE are answered with 405, naming only POST as the method to use')]
    public function testGetAndDeleteAreNotAllowed(): void
    {
        $get = $this->send('GET', headers: ['Accept' => 'text/event-stream']);
        $delete = $this->send('DELETE', headers: [StreamableHttpTransport::SESSION_HEADER => 'f0e1d2c3-b4a5-4697-8879-6a5b4c3d2e1f']);

        $this->assertSame(405, $get->getStatusCode());
        $this->assertSame('POST, OPTIONS', $get->getHeaderLine('Allow'));
        $this->assertSame(405, $delete->getStatusCode());
        $this->assertSame('POST, OPTIONS', $delete->getHeaderLine('Allow'));
    }

    #[TestDox('notifications sent during a request reach the client on its stream, without a session id')]
    public function testNotificationsDuringARequestAreStreamed(): void
    {
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'notify', 'arguments' => []]));

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));
        $this->assertFalse($response->hasHeader(StreamableHttpTransport::SESSION_HEADER));

        $stream = $this->readStream($response);

        $this->assertSame(2, substr_count($stream, 'notifications\/progress'));
        $this->assertStringContainsString('"text":"done"', $stream);
        $this->assertSame([], $this->storedSessions());
    }

    #[TestDox('a request to the client fails right away instead of waiting for an answer that cannot arrive')]
    public function testRequestToTheClientFailsRightAway(): void
    {
        $started = microtime(true);
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'ask', 'arguments' => []]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('without sessions', self::decode($response)['result']['content'][0]['text']);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame([], $this->storedSessions());
    }

    #[TestDox('a request to the client after a notification fails right away too')]
    public function testRequestToTheClientAfterANotificationFailsRightAway(): void
    {
        $started = microtime(true);
        $response = $this->send('POST', $this->request('tools/call', ['name' => 'notify-then-ask', 'arguments' => []]));
        $stream = $this->readStream($response);

        $this->assertStringContainsString('notifications\/progress', $stream);
        $this->assertStringContainsString('Failed to send the message to the client.', $stream);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertSame([], $this->storedSessions());
    }

    #[TestDox('the modern era is served as before')]
    public function testModernEraIsServedAsBefore(): void
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'server/discover',
            'params' => ['_meta' => [
                RequestMeta::PROTOCOL_VERSION => ProtocolVersion::V2026_07_28->value,
                RequestMeta::CLIENT_CAPABILITIES => new \stdClass(),
            ]],
        ], \JSON_THROW_ON_ERROR);

        $response = $this->send('POST', $body, [
            'MCP-Protocol-Version' => ProtocolVersion::V2026_07_28->value,
            'Mcp-Method' => 'server/discover',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([ProtocolVersion::V2026_07_28->value], self::decode($response)['result']['supportedVersions']);
    }

    private function server(): Server
    {
        return Server::builder()
            ->setServerInfo('sessionless', '1.0.0')
            ->setSession(new FileSessionStore($this->sessions))
            ->withoutSessions()
            ->addTool(static fn (string $text): string => $text, 'echo')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->notify(new ProgressNotification('p', 0.5));
                $context->getClientGateway()->notify(new ProgressNotification('p', 1.0));

                return 'done';
            }, 'notify')
            ->addTool(static function (RequestContext $context): string {
                $answer = $context->getClientGateway()->request(new PingRequest(), 5);

                return $answer instanceof Error ? $answer->message : 'answered';
            }, 'ask')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->notify(new ProgressNotification('p', 0.5));
                $answer = $context->getClientGateway()->request(new PingRequest(), 5);

                return $answer instanceof Error ? $answer->message : 'answered';
            }, 'notify-then-ask')
            ->build();
    }

    private function initialize(): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => ['elicitation' => new \stdClass()],
                'clientInfo' => ['name' => 'handshake-client', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params = []): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => (object) $params], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $method, string $body = '', array $headers = []): ResponseInterface
    {
        $request = $this->factory->createServerRequest($method, 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        if ('POST' === $method && !str_contains($body, '"initialize"')) {
            $request = $request->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, ProtocolVersion::V2025_11_25->value);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->server()->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
    }

    /**
     * @return list<string>
     */
    private function storedSessions(): array
    {
        return glob($this->sessions.'/*') ?: [];
    }

    /**
     * Reads an SSE response; the stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
     */
    private function readStream(ResponseInterface $response): string
    {
        $output = '';
        ob_start(static function (string $chunk) use (&$output): string {
            $output .= $chunk;

            return '';
        });
        try {
            $response->getBody()->getContents();
        } finally {
            ob_end_flush();
        }

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
