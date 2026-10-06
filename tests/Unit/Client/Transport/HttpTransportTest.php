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
use Mcp\Client\CancellationTokenInterface;
use Mcp\Client\Configuration;
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Notification\LoggingMessageNotification;
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

        // The handshake era is what these servers speak, so no probe precedes it.
        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(1)
            ->build();

        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $this->assertTrue($client->isConnected());
        $this->assertSame('test-server', $client->getServerInfo()?->name);
    }

    #[TestDox('the server-minted session ID is exposed while connected and cleared on close')]
    public function testSessionIdIsExposedAndClearedOnClose(): void
    {
        $httpClient = new class implements ClientInterface {
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
                ], \JSON_THROW_ON_ERROR);

                return new Response(200, [
                    'Content-Type' => 'application/json',
                    'Mcp-Session-Id' => 'session-abc123',
                ], $payload);
            }
        };

        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);

        $this->assertNull($transport->getSessionId());

        // The handshake era is what these servers speak, so no probe precedes it.
        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(1)
            ->build();

        $client->connect($transport);

        $this->assertSame('session-abc123', $transport->getSessionId());

        $client->disconnect();

        $this->assertNull($transport->getSessionId());
    }

    /**
     * @return iterable<string, array{int, array<string, string>, string}>
     */
    public static function probeRefusalProvider(): iterable
    {
        yield 'a JSON-RPC error without an id' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":null,"error":{"code":-32000,"message":"Bad Request: Server not initialized"}}'];
        yield 'a JSON-RPC error under another id' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":999,"error":{"code":-32600,"message":"Bad Request"}}'];
        yield 'a body under the request id that is no JSON-RPC message' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"message":"Bad Request"}'];
        yield 'an error under the request id without a message' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"error":{"code":-32600}}'];
        yield 'a null result under the request id' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":null}'];
        yield 'a scalar result under the request id' => [400, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":"nope"}'];
        yield 'an error under the request id without the JSON-RPC version' => [400, ['Content-Type' => 'application/json'], '{"id":1,"error":{"code":-32600,"message":"Bad Request"}}'];
        yield 'an empty body' => [400, [], ''];
        yield 'a plain-text body' => [404, ['Content-Type' => 'text/plain'], 'Not Found'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('probeRefusalProvider')]
    #[TestDox('a handshake-era server refusing the probe with $_dataName is reached through the handshake at once')]
    public function testRefusedProbeFallsBackWithoutWaiting(int $status, array $headers, string $body): void
    {
        $httpClient = new class($status, $headers, $body) implements ClientInterface {
            /** @var list<string> */
            public array $methods = [];

            /**
             * @param array<string, string> $headers
             */
            public function __construct(private readonly int $status, private readonly array $headers, private readonly string $body)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $decoded = json_decode((string) $request->getBody(), true);
                $this->methods[] = $decoded['method'] ?? '';

                if ('initialize' !== ($decoded['method'] ?? null)) {
                    return 'server/discover' === ($decoded['method'] ?? null)
                        ? new Response($this->status, $this->headers, $this->body)
                        : new Response(202);
                }

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $decoded['id'],
                    'result' => [
                        'protocolVersion' => '2025-11-25',
                        'capabilities' => new \stdClass(),
                        'serverInfo' => ['name' => 'legacy-server', 'version' => '1.0.0'],
                    ],
                ]));
            }
        };

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setInitTimeout(5)
            ->build();

        $started = microtime(true);
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $this->assertLessThan(1, microtime(true) - $started, 'the refusal must not be waited out like silence');
        $this->assertSame(['server/discover', 'initialize', 'notifications/initialized'], $httpClient->methods);
        $this->assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
        $this->assertSame('legacy-server', $client->getServerInfo()?->name);
    }

    #[TestDox('a modern refusal under 400 is read as the modern error it is, not as a handshake-era server')]
    public function testModernRefusalIsRead(): void
    {
        $httpClient = new class implements ClientInterface {
            /** @var list<string> */
            public array $methods = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $decoded = json_decode((string) $request->getBody(), true);
                $this->methods[] = $decoded['method'] ?? '';

                return new Response(400, ['Content-Type' => 'application/json'], (string) json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $decoded['id'],
                    'error' => ['code' => -32022, 'message' => 'Unsupported protocol version', 'data' => ['requested' => '2026-07-28', 'supported' => ['2099-01-01']]],
                ]));
            }
        };

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->setInitTimeout(5)
            ->setMaxRetries(0)
            ->build();

        try {
            $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));
            $this->fail('A modern server sharing no revision with the client must fail the connection.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('2099-01-01', $e->getMessage());
        }

        // A modern server: no fallback to a handshake it does not have.
        $this->assertSame(['server/discover'], $httpClient->methods);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedResponseProvider(): iterable
    {
        yield 'an empty body' => [''];
        yield 'an error under the same id' => ['{"jsonrpc":"2.0","id":1,"error":{"code":-32600,"message":"Bad Request"}}'];
    }

    #[DataProvider('refusedResponseProvider')]
    #[TestDox('a refused answer to a server request never answers a client request sharing its id: $_dataName')]
    public function testRefusedResponseLeavesClientRequestsAlone(string $body): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(new Response(404, ['Content-Type' => 'application/json'], $body));

        $transport = new HttpTransport('https://example.test/mcp', [], $httpClient, $this->factory, $this->factory);
        $state = new ClientState();
        $transport->setState($state);
        $dispatched = [];
        $transport->onMessage(static function (string $message) use (&$dispatched): void {
            $dispatched[] = $message;
        });
        $state->addPendingRequest(1, 120);

        $transport->send('{"jsonrpc":"2.0","id":1,"result":{}}');

        $this->assertNull($state->consumeResponse(1));
        $this->assertSame([], $dispatched);
    }

    #[TestDox('progress and other notifications on one stream reach the caller in the order they were sent')]
    public function testProgressKeepsItsPlaceAmongNotifications(): void
    {
        $order = [];
        $protocol = new Protocol(notificationHandlers: [new LoggingNotificationHandler(static function (LoggingMessageNotification $n) use (&$order): void {
            $order[] = 'log '.$n->data;
        })]);
        $transport = $this->createTransport();
        $protocol->connect($transport, new Configuration(new Implementation('test', '1.0.0'), new ClientCapabilities()));
        $protocol->setProgressCallback(static function (float $progress) use (&$order): void {
            $order[] = 'progress '.$progress;
        });

        $this->setActiveStream($transport, $this->factory->createStream(
            'data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":"t","progress":1}}'."\n\n"
            .'data: {"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"done"}}'."\n\n",
        ));
        $this->invokeProcessSseStream($transport);

        $this->assertSame(['progress 1', 'log done'], $order);
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

    /** @return iterable<string, array{bool}> */
    public static function cancellationPointProvider(): iterable
    {
        yield 'before first SSE read' => [true];
        yield 'between SSE reads' => [false];
    }

    #[DataProvider('cancellationPointProvider')]
    public function testCancellationClosesSseBodyAndAllowsNextCall(bool $beforeRead): void
    {
        $token = new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $body = $this->createMock(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->expects($beforeRead ? $this->never() : $this->once())->method('read')->willReturnCallback(static function () use ($token): string {
            $token->cancelled = true;

            return ": keepalive\n\n";
        });
        $body->expects($this->once())->method('close');

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use ($token, $body, $beforeRead): ResponseInterface {
            $payload = json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR);
            if ('initialize' === $payload['method']) {
                $result = [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => ['tools' => []],
                    'serverInfo' => ['name' => 'test', 'version' => '1'],
                ];
            } elseif ('tools/call' === $payload['method']) {
                if ('slow' === $payload['params']['name']) {
                    $token->cancelled = $beforeRead;

                    return new Response(200, ['Content-Type' => 'text/event-stream'], $body);
                }
                $result = ['content' => [['type' => 'text', 'text' => 'next call']]];
            } else {
                return new Response(202);
            }

            return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'jsonrpc' => '2.0', 'id' => $payload['id'], 'result' => $result,
            ], \JSON_THROW_ON_ERROR));
        });
        $client = Client::builder()->setClientInfo('test', '1')->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        try {
            $client->callTool('slow', cancellation: $token);
            $this->fail('Expected cancellation.');
        } catch (RequestCancelledException) {
            $this->assertTrue($client->isConnected());
        }
        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    public function testExpiredDeadlineClosesBodyBeforeReading(): void
    {
        $transport = $this->createTransport();
        $state = new ClientState();
        $transport->setState($state);
        $state->addPendingRequest(1, 30);
        $body = $this->createMock(StreamInterface::class);
        $body->expects($this->never())->method('read');
        $body->expects($this->once())->method('close');
        $this->setActiveStream($transport, $body);

        $fiber = new \Fiber(static fn () => \Fiber::suspend([
            'type' => 'await_response',
            'request_id' => 1,
            'timeout' => 30,
            'deadline' => microtime(true) - 1,
        ]));

        $this->expectException(TimeoutException::class);
        $transport->runRequest($fiber);
    }

    /** @return iterable<string, array{string}> */
    public static function notificationAnswerProvider(): iterable
    {
        yield 'JSON answer' => ['application/json'];
        yield 'SSE answer' => ['text/event-stream'];
    }

    #[DataProvider('notificationAnswerProvider')]
    #[TestDox('a notification answer is discarded rather than parked in the stream a request is waiting on: $_dataName')]
    public function testNotificationAnswerIsDiscarded(string $contentType): void
    {
        $original = $this->createMock(StreamInterface::class);
        $original->expects($this->never())->method('close');

        $answer = $this->createMock(StreamInterface::class);
        $answer->expects($this->once())->method('close');
        $answer->expects($this->never())->method('getContents');

        $httpClient = new class($answer, $contentType) implements ClientInterface {
            public function __construct(
                private readonly StreamInterface $answer,
                private readonly string $contentType,
            ) {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => $this->contentType], $this->answer);
            }
        };

        $transport = new HttpTransport('https://example.test/mcp', [], $httpClient, $this->factory, $this->factory);
        $dispatched = [];
        $transport->onMessage(static function (string $message) use (&$dispatched): void {
            $dispatched[] = $message;
        });
        $this->setActiveStream($transport, $original);

        $transport->send(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/cancelled',
            'params' => ['requestId' => 1],
        ], \JSON_THROW_ON_ERROR));

        $this->assertSame($original, $this->readPrivate($transport, 'activeStream'), 'the interrupted request keeps its own stream');
        $this->assertSame([], $dispatched, 'nothing may be dispatched as an answer to a notification');
    }

    #[TestDox('a token that flips while the JSON body is read cancels the call, and a handshake-era server is told')]
    public function testCancellationDuringJsonBodyReadIsReported(): void
    {
        $token = new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $body = new class {
            public string $contents = '';
        };
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturnCallback(static function () use ($body, $token): string {
            $token->cancelled = true;

            return $body->contents;
        });

        $httpClient = new RecordingHttpClient(static function (array $message) use ($body, $stream): ?ResponseInterface {
            if ('slow' !== ($message['params']['name'] ?? null)) {
                return null;
            }

            // The server answered in full; the caller stopped caring mid-read.
            $body->contents = json_encode([
                'jsonrpc' => '2.0',
                'id' => $message['id'],
                'result' => ['content' => [['type' => 'text', 'text' => 'abandoned']]],
            ], \JSON_THROW_ON_ERROR);

            return new Response(200, ['Content-Type' => 'application/json'], $stream);
        });

        // Reporting a cancellation is a handshake-era exchange over HTTP, see below.
        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        try {
            $client->callTool('slow', cancellation: $token);
            $this->fail('Expected cancellation.');
        } catch (RequestCancelledException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
        }

        $abandoned = $httpClient->callId('slow');
        $cancellations = $httpClient->messagesOfMethod('notifications/cancelled');
        $this->assertCount(1, $cancellations);
        $this->assertSame($abandoned, $cancellations[0]['params']['requestId'] ?? null);
        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    /** @return iterable<string, array{ProtocolVersion, bool}> */
    public static function revisionProvider(): iterable
    {
        yield 'handshake-era HTTP reports the cancellation' => [ProtocolVersion::V2025_11_25, true];
        yield 'modern HTTP leaves it to the closed response stream' => [ProtocolVersion::V2026_07_28, false];
    }

    #[DataProvider('revisionProvider')]
    #[TestDox('cancellation signalling: $_dataName')]
    public function testCancellationSignallingDependsOnTheRevision(ProtocolVersion $version, bool $expectsNotification): void
    {
        $token = new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $httpClient = new RecordingHttpClient(static function (array $message) use ($token): ?ResponseInterface {
            if ('slow' === ($message['params']['name'] ?? null)) {
                $token->cancelled = true;
            }

            return null;
        });

        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion($version)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        try {
            $client->callTool('slow', cancellation: $token);
            $this->fail('Expected cancellation.');
        } catch (RequestCancelledException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
        }

        $cancellations = $httpClient->messagesOfMethod('notifications/cancelled');

        if (!$expectsNotification) {
            $this->assertSame([], $cancellations);
        } else {
            $this->assertCount(1, $cancellations);
            $this->assertArrayNotHasKey('id', $cancellations[0], 'the cancellation goes out as a notification');
            $this->assertSame($httpClient->callId('slow'), $cancellations[0]['params']['requestId'] ?? null);
        }

        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    private function createTransport(int $maxSseBufferBytes = 8 * 1024 * 1024): HttpTransport
    {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            httpClient: $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            maxSseBufferBytes: $maxSseBufferBytes,
        );
    }

    private function setActiveStream(HttpTransport $transport, StreamInterface $stream): void
    {
        (new \ReflectionProperty($transport, 'activeStream'))->setValue($transport, $stream);
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

/**
 * PSR-18 client that answers both wire formats this SDK speaks and records every
 * message it was handed, so a test can inspect what went out.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /**
     * The hook sees every request first and may return its own answer; a null
     * return falls through to the default one.
     *
     * @param (\Closure(array<string, mixed>): ?ResponseInterface)|null $intercept
     */
    public function __construct(private readonly ?\Closure $intercept = null)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $message */
        $message = json_decode((string) $request->getBody(), true, flags: \JSON_THROW_ON_ERROR);
        $this->messages[] = $message;

        if (null !== $this->intercept) {
            $answer = ($this->intercept)($message);
            if (null !== $answer) {
                return $answer;
            }
        }

        return match ($message['method'] ?? null) {
            'initialize' => $this->json($message, [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
            ]),
            'server/discover' => $this->json($message, [
                'resultType' => 'complete',
                'supportedVersions' => [ProtocolVersion::V2026_07_28->value],
                'capabilities' => ['tools' => []],
                'serverInfo' => ['name' => 'test-server', 'version' => '1.0.0'],
            ]),
            'tools/call' => $this->json($message, [
                'content' => [['type' => 'text', 'text' => 'fast' === ($message['params']['name'] ?? null) ? 'next call' : 'abandoned']],
            ]),
            default => new Response(202),
        };
    }

    /**
     * Every recorded message carrying this method.
     *
     * @return list<array<string, mixed>>
     */
    public function messagesOfMethod(string $method): array
    {
        return array_values(array_filter($this->messages, static fn (array $message): bool => $method === ($message['method'] ?? null)));
    }

    /**
     * The request id recorded for this tool name.
     */
    public function callId(string $name): int|string
    {
        foreach ($this->messages as $message) {
            if ('tools/call' === ($message['method'] ?? null) && $name === ($message['params']['name'] ?? null)) {
                $id = $message['id'] ?? null;

                if (\is_int($id) || \is_string($id)) {
                    return $id;
                }
            }
        }

        throw new \RuntimeException(\sprintf('No recorded "tools/call" for tool "%s".', $name));
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $result
     */
    private function json(array $message, array $result): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => $message['id'],
            'result' => $result,
        ], \JSON_THROW_ON_ERROR));
    }
}
