<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Transport;

use Mcp\Client;
use Mcp\Client\Configuration;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\HttpTransportException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\SessionExpiredException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\PingRequest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class HttpTransportTest extends TestCase
{
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function frameProvider(): iterable
    {
        yield 'LF line endings' => ["event: message\ndata: %s\n\n"];
        // sse-starlette (and therefore every MCP Python SDK server) defaults to CRLF.
        yield 'CRLF line endings' => ["event: message\r\ndata: %s\r\n\r\n"];
        yield 'CR line endings' => ["event: message\rdata: %s\r\r"];
        yield 'with an id field' => ["id: 1\r\nevent: message\r\ndata: %s\r\n\r\n"];
        yield 'no trailing blank line' => ["event: message\ndata: %s\n"];
        yield 'preceded by a comment' => [": ping\n\nevent: message\ndata: %s\n\n"];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function emptyBodyStatusProvider(): iterable
    {
        yield '202 Accepted' => [202];
        yield '204 No Content' => [204];
    }

    #[DataProvider('frameProvider')]
    #[TestDox('initialization succeeds for an SSE response framed as: $_dataName')]
    public function testInitializeParsesSseFraming(string $frame): void
    {
        $httpClient = new class($frame) implements ClientInterface {
            public function __construct(private readonly string $frame)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $decoded = json_decode((string) $request->getBody(), true);

                if ('initialize' !== ($decoded['method'] ?? null)) {
                    return new Response(202);
                }

                $payload = json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $decoded['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
                    ],
                ]);

                return new Response(200, [
                    'Content-Type' => 'text/event-stream',
                    'Mcp-Session-Id' => 'abc123',
                ], \sprintf($this->frame, $payload));
            }
        };

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setInitTimeout(1)
            ->build();

        $client->connect($this->createTransport($httpClient));

        $this->assertTrue($client->isConnected());
        $this->assertSame('test-server', $client->getServerInfo()?->name);
    }

    #[TestDox('SSE stream is aborted before the buffer can exceed the configured cap')]
    public function testSseBufferIsBoundedByConfiguredCap(): void
    {
        $transport = $this->createTransport(maxSseBufferBytes: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        // A server that streams data without ever sending the "\n\n" delimiter.
        $this->setActiveStream($transport, $this->factory->createStream(str_repeat('a', 4096)));

        $this->invokeProcessSseStream($transport);

        $this->assertSame('', $this->readPrivate($transport, 'sseBuffer'), 'buffer must be cleared on abort');
        $this->assertNull($this->readPrivate($transport, 'activeStream'), 'stream must be released on abort');
    }

    #[TestDox('aborting the SSE stream fails the in-flight request immediately instead of waiting for its timeout')]
    public function testAbortFailsPendingRequestFast(): void
    {
        $transport = $this->createTransport(maxSseBufferBytes: 64);
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);

        $this->setActiveStream($transport, $this->factory->createStream(str_repeat('a', 4096)));

        $this->invokeProcessSseStream($transport);

        $response = $state->consumeResponse(1);
        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::INTERNAL_ERROR, $response->code);
        $this->assertSame(1, $response->id);
    }

    #[TestDox('well-formed delimited events are parsed and dispatched')]
    public function testWellFormedEventsStillParse(): void
    {
        $transport = $this->createTransport();
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->setActiveStream($transport, $this->factory->createStream("data: hello\n\ndata: world\n\n"));

        $this->invokeProcessSseStream($transport);

        $this->assertSame(['hello', 'world'], $messages);
    }

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(maxSseBufferBytes: 0);
    }

    #[TestDox('HTTP 404 with a session id and a non-JSON-RPC body clears the session and throws a session-expired error')]
    public function test404WithSessionClearsSessionAndThrowsSessionExpiredException(): void
    {
        $transport = $this->createTransport($this->createStubClient(404, ['Content-Type' => 'text/plain'], 'Not Found'));
        $state = new ClientState();
        $state->setInitialized(true);
        $transport->setState($state);
        $this->setSessionId($transport, 'abc-123');

        try {
            $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');
            $this->fail('Expected SessionExpiredException to be thrown.');
        } catch (SessionExpiredException $e) {
            $this->assertStringContainsString('404', $e->getMessage());
        }

        $this->assertNull($this->readPrivate($transport, 'sessionId'), 'The session id must be cleared so the client can re-initialize.');
        $this->assertFalse($state->isInitialized(), 'The client must be marked un-initialized so isConnected() reports false.');
    }

    #[TestDox('HTTP 404 without a session id is a plain transport error')]
    public function test404WithoutSessionThrowsHttpTransportException(): void
    {
        $transport = $this->createTransport($this->createStubClient(404, ['Content-Type' => 'text/plain'], 'Not Found'));

        try {
            $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');
            $this->fail('Expected HttpTransportException to be thrown.');
        } catch (HttpTransportException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    #[TestDox('a non-success status throws a transport error carrying the status and a body snippet')]
    public function testNonSuccessStatusThrowsHttpTransportException(): void
    {
        $transport = $this->createTransport($this->createStubClient(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'));

        try {
            $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');
            $this->fail('Expected HttpTransportException to be thrown.');
        } catch (HttpTransportException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertStringContainsString('500', $e->getMessage());
            $this->assertStringContainsString('Internal Server Error', $e->getMessage());
        }
    }

    #[TestDox('a 404 whose body is a JSON-RPC error is dispatched as a message and keeps the session id')]
    public function test404JsonRpcErrorBodyIsDispatchedAndPreservesSession(): void
    {
        $body = '{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}';
        $transport = $this->createTransport($this->createStubClient(404, ['Content-Type' => 'application/json'], $body));
        $state = new ClientState();
        $state->addPendingRequest(1, 30);
        $transport->setState($state);
        $this->setSessionId($transport, 'abc-123');

        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame([$body], $messages, 'A JSON-RPC error body must be dispatched through the message handler.');
        $this->assertSame('abc-123', $this->readPrivate($transport, 'sessionId'), 'A JSON-RPC error body must not clear a live session.');
    }

    #[TestDox('a non-2xx JSON-RPC error body resolves the request with the error instead of throwing a transport exception')]
    public function testNonSuccessJsonRpcErrorBodyResolvesRequestWithError(): void
    {
        $transport = $this->createTransport($this->createStubClient(404, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}'));
        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration());

        $fiber = new \Fiber(static fn () => $protocol->request(new PingRequest(), 30));
        $result = $transport->runRequest($fiber);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(Error::METHOD_NOT_FOUND, $result->code);
    }

    #[TestDox('a non-404 failure keeps the session id')]
    public function testSessionIdIsPreservedOnNon404Failure(): void
    {
        $transport = $this->createTransport($this->createStubClient(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'));
        $this->setSessionId($transport, 'abc-123');

        try {
            $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');
            $this->fail('Expected HttpTransportException to be thrown.');
        } catch (HttpTransportException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertSame('abc-123', $this->readPrivate($transport, 'sessionId'), 'A non-404 failure must not clear the session id.');
    }

    #[DataProvider('emptyBodyStatusProvider')]
    #[TestDox('a $_dataName response is accepted without a body')]
    public function test2xxWithEmptyBodyIsAccepted(int $statusCode): void
    {
        $transport = $this->createTransport($this->createStubClient($statusCode, ['Content-Type' => 'application/json'], ''));
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame([], $messages);
    }

    #[TestDox('a 200 application/json response is dispatched normally')]
    public function test200JsonResponseIsHandledNormally(): void
    {
        $transport = $this->createTransport($this->createStubClient(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"status":"ok"}}'));
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame(['{"jsonrpc":"2.0","id":1,"result":{"status":"ok"}}'], $messages);
    }

    #[TestDox('the Mcp-Protocol-Version header is sent once a protocol version is negotiated')]
    public function testSendsProtocolVersionHeaderWhenNegotiated(): void
    {
        $httpClient = new class implements ClientInterface {
            public ?string $protocolVersionHeader = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->protocolVersionHeader = $request->getHeaderLine('Mcp-Protocol-Version');

                return new Response(200, ['Content-Type' => 'application/json']);
            }
        };

        $transport = $this->createTransport($httpClient);
        $state = new ClientState();
        $state->setProtocolVersion(ProtocolVersion::V2025_11_25);
        $transport->setState($state);

        $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame('2025-11-25', $httpClient->protocolVersionHeader);
    }

    #[TestDox('the Mcp-Protocol-Version header is omitted before a protocol version is negotiated')]
    public function testOmitsProtocolVersionHeaderBeforeNegotiation(): void
    {
        $httpClient = new class implements ClientInterface {
            public ?string $protocolVersionHeader = 'unset';

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->protocolVersionHeader = $request->getHeaderLine('Mcp-Protocol-Version');

                return new Response(200, ['Content-Type' => 'application/json']);
            }
        };

        $transport = $this->createTransport($httpClient);
        $transport->setState(new ClientState()); // version not yet negotiated

        $transport->send('{"jsonrpc":"2.0","method":"ping","id":1}');

        $this->assertSame('', $httpClient->protocolVersionHeader);
    }

    #[TestDox('closing the session sends the negotiated protocol version header')]
    public function testCloseSendsProtocolVersionHeader(): void
    {
        $httpClient = new class implements ClientInterface {
            public ?string $method = null;
            public ?string $protocolVersionHeader = null;
            public ?string $sessionIdHeader = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->method = $request->getMethod();
                $this->protocolVersionHeader = $request->getHeaderLine('Mcp-Protocol-Version');
                $this->sessionIdHeader = $request->getHeaderLine('Mcp-Session-Id');

                return new Response(200, ['Content-Type' => 'application/json']);
            }
        };

        $transport = $this->createTransport($httpClient);
        $state = new ClientState();
        $state->setProtocolVersion(ProtocolVersion::V2025_11_25);
        $transport->setState($state);
        $this->setSessionId($transport, 'abc-123');

        $transport->close();

        $this->assertSame('DELETE', $httpClient->method);
        $this->assertSame('2025-11-25', $httpClient->protocolVersionHeader);
        $this->assertSame('abc-123', $httpClient->sessionIdHeader);
    }

    #[TestDox('runRequest clears its active state when a request throws')]
    public function testRunRequestCleansUpWhenSendThrows(): void
    {
        $transport = $this->createTransport($this->createStubClient(500, ['Content-Type' => 'text/plain'], 'Internal Server Error'));
        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration());

        $fiber = new \Fiber(static fn () => $protocol->request(new PingRequest(), 30));

        try {
            $transport->runRequest($fiber);
            $this->fail('Expected HttpTransportException to be thrown.');
        } catch (HttpTransportException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertNull($this->readPrivate($transport, 'activeFiber'), 'The fiber must be released when a request throws.');
        $this->assertNull($this->readPrivate($transport, 'activeProgressCallback'), 'The progress callback must be released when a request throws.');
        $this->assertNull($this->readPrivate($transport, 'activeStream'), 'The stream must be released when a request throws.');
    }

    private function createTransport(?ClientInterface $httpClient = null, int $maxSseBufferBytes = 8 * 1024 * 1024): HttpTransport
    {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            httpClient: $httpClient ?? $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            maxSseBufferBytes: $maxSseBufferBytes,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function createStubClient(int $statusCode, array $headers = [], string $body = ''): ClientInterface
    {
        return new class($statusCode, $headers, $body) implements ClientInterface {
            /**
             * @param array<string, string> $headers
             */
            public function __construct(
                private readonly int $statusCode,
                private readonly array $headers,
                private readonly string $body,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response($this->statusCode, $this->headers, $this->body);
            }
        };
    }

    private function createConfiguration(): Configuration
    {
        return new Configuration(
            clientInfo: new Implementation('client-app', '1.0.0'),
            capabilities: new ClientCapabilities(),
            protocolVersion: ProtocolVersion::V2025_11_25,
        );
    }

    private function setActiveStream(HttpTransport $transport, StreamInterface $stream): void
    {
        (new \ReflectionProperty($transport, 'activeStream'))->setValue($transport, $stream);
    }

    private function setSessionId(HttpTransport $transport, ?string $sessionId): void
    {
        (new \ReflectionProperty($transport, 'sessionId'))->setValue($transport, $sessionId);
    }

    private function invokeProcessSseStream(HttpTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'processSSEStream'))->invoke($transport);
    }

    private function readPrivate(HttpTransport $transport, string $property): mixed
    {
        return (new \ReflectionProperty($transport, $property))->getValue($transport);
    }
}
