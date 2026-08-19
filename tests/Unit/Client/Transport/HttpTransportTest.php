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
use Mcp\Schema\JsonRpc\Response as JsonRpcResponse;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
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

    /** @return iterable<string, array{string, string}> */
    public static function expiredSessionResponseProvider(): iterable
    {
        yield 'empty response' => ['', ''];
        yield 'plain-text response' => ['text/plain', 'Session expired'];
    }

    #[DataProvider('expiredSessionResponseProvider')]
    public function testExpiredSessionIsAConnectionFailure(string $contentType, string $body): void
    {
        $stream = $this->factory->createStream($body);
        $expired = new Response(404, ['Content-Type' => $contentType], $stream);

        $requests = [];
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->exactly(2))->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use (&$requests, $expired): ResponseInterface {
                $requests[] = $request;

                return 1 === \count($requests)
                    ? new Response(200, ['Mcp-Session-Id' => 'expired-session'])
                    : $expired;
            },
        );
        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);
        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');
        $this->assertSame('expired-session', $transport->getSessionId());

        try {
            $transport->send('{"jsonrpc":"2.0","id":2,"method":"tools/call"}');
            $this->fail('A terminated session must fail immediately, not wait for a request timeout.');
        } catch (ConnectionException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('The HTTP session has expired.', $e->getMessage());
        }

        $this->assertSame('expired-session', $requests[1]->getHeaderLine('Mcp-Session-Id'));
        $this->assertNull($transport->getSessionId());
        $this->assertFalse($stream->isReadable());
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

        $this->assertSame(0, $this->readPrivate($transport, 'sseParser')->bufferedBytes(), 'buffer must be cleared on abort');
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

    #[TestDox('an interrupted SSE request resumes with its latest event ID after the server retry delay')]
    public function testReconnectsWithLatestEventIdAfterServerDelay(): void
    {
        $clock = new SteppingClock();
        $response = '{"jsonrpc":"2.0","id":7,"result":{"ok":true}}';
        $httpClient = RecordingHttpClient::queued([
            new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Mcp-Session-Id' => 'session-1',
            ], "id: first\ndata:\n\nid: latest\nretry: 500\ndata:\n\n"),
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: final\ndata: {$response}\n\n"),
        ], $clock);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            headers: [
                'Authorization' => 'Bearer secret',
                'MCP-Protocol-Version' => '2025-11-25',
            ],
            clock: $clock,
        );

        $messages = [];
        $result = $this->runRequest($transport, 7, messages: $messages);

        $this->assertInstanceOf(JsonRpcResponse::class, $result);
        $this->assertSame([$response], $messages);
        $this->assertCount(2, $httpClient->requests);
        $this->assertDelay(500, $httpClient->sentAtMs[1] - $httpClient->sentAtMs[0]);

        $request = $httpClient->requests[1];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('text/event-stream', $request->getHeaderLine('Accept'));
        $this->assertSame('session-1', $request->getHeaderLine('Mcp-Session-Id'));
        $this->assertSame('latest', $request->getHeaderLine('Last-Event-ID'));
        $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-11-25', $request->getHeaderLine('MCP-Protocol-Version'));
    }

    #[TestDox('fallback reconnect delays double, cap at ten seconds, and stop after five attempts')]
    public function testReconnectBackoffAndAttemptLimit(): void
    {
        $clock = new SteppingClock();
        $responses = [new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n")];

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $responses[] = new Response(503);
        }

        $httpClient = RecordingHttpClient::queued($responses, $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $result = $this->runRequest($transport, 1);

        $this->assertCount(6, $httpClient->requests, 'one POST plus exactly five reconnect attempts');
        foreach ([1_000, 2_000, 4_000, 8_000, 10_000] as $attempt => $delay) {
            $request = $httpClient->requests[$attempt + 1];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('event-0', $request->getHeaderLine('Last-Event-ID'));
            $this->assertDelay($delay, $httpClient->sentAtMs[$attempt + 1] - $httpClient->sentAtMs[$attempt]);
        }

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('maximum of 5 reconnect attempts', $result->message);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function reconnectStatusProvider(): iterable
    {
        yield 'session expired' => [404, 2];
        yield 'no GET stream offered' => [405, 2];
        yield 'unauthorized' => [401, 2];
        yield 'request timeout' => [408, 6];
        yield 'rate limited' => [429, 6];
        yield 'server error' => [503, 6];
    }

    #[DataProvider('reconnectStatusProvider')]
    #[TestDox('a reconnect answered with HTTP $status fails after $requests requests ($_dataName)')]
    public function testReconnectStatusHandling(int $status, int $requests): void
    {
        $clock = new SteppingClock();
        $responses = [new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n")];

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $responses[] = new Response($status);
        }

        $httpClient = RecordingHttpClient::queued($responses, $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $this->assertInstanceOf(Error::class, $this->runRequest($transport, 1));
        $this->assertCount($requests, $httpClient->requests);
    }

    #[TestDox('a reconnect answered with 404 expires the session like any session-bound request')]
    public function testReconnectOnExpiredSessionInvalidatesConnection(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'session'], "id: event-0\ndata:\n\n"),
            new Response(404),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);
        $state = new ClientState();
        $state->setInitialized(true);

        $result = $this->runRequest($transport, 1, state: $state);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('HTTP session has expired', $result->message);
        $this->assertSame('session', $httpClient->requests[1]->getHeaderLine('Mcp-Session-Id'));
        $this->assertNull($transport->getSessionId());
        $this->assertFalse($state->isInitialized());
    }

    #[TestDox('a reconnect that does not answer with an event stream is retried')]
    public function testReconnectWithoutEventStreamIsRetried(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n"),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-1\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $this->assertInstanceOf(JsonRpcResponse::class, $this->runRequest($transport, 1));
        $this->assertCount(3, $httpClient->requests);
        $this->assertSame('event-0', $httpClient->requests[2]->getHeaderLine('Last-Event-ID'));
    }

    #[TestDox('a stream that fails while reading is reported and resumed')]
    public function testReadErrorIsResumed(): void
    {
        $clock = new SteppingClock();
        $reads = 0;
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('eof')->willReturn(false);
        $stream->method('read')->willReturnCallback(static function () use (&$reads): string {
            if (1 === ++$reads) {
                return "id: event-0\ndata:\n\n";
            }

            throw new \RuntimeException('Connection reset by peer');
        });
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-1\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $errors = [];
        $result = $this->runRequest($transport, 1, errors: $errors);

        $this->assertInstanceOf(JsonRpcResponse::class, $result);
        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('event-0', $httpClient->requests[1]->getHeaderLine('Last-Event-ID'));
        $this->assertCount(1, $errors);
        $this->assertSame('Connection reset by peer', $errors[0]->getMessage());
    }

    #[TestDox('a stream on a protocol version without resumption fails instead of reconnecting')]
    public function testStreamIsNotResumedOnModernProtocolVersion(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $state = new ClientState();
        $state->setProtocolVersion(ProtocolVersion::V2026_07_28);

        $result = $this->runRequest($transport, 1, state: $state);

        $this->assertCount(1, $httpClient->requests);
        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('protocol version 2026-07-28 does not resume streams', $result->message);
    }

    #[TestDox('a resumed stream that only replays the same empty event still consumes the attempt cap')]
    public function testReplayingSameEventDoesNotResetAttempts(): void
    {
        $clock = new SteppingClock();
        $priming = "id: priming\nretry: 0\ndata:\n\n";
        $responses = [];

        for ($attempt = 0; $attempt <= 5; ++$attempt) {
            $responses[] = new Response(200, ['Content-Type' => 'text/event-stream'], $priming);
        }

        $httpClient = RecordingHttpClient::queued($responses, $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $result = $this->runRequest($transport, 2);

        $this->assertCount(6, $httpClient->requests, 'one POST plus exactly five reconnect attempts');
        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('maximum of 5 reconnect attempts', $result->message);
    }

    #[TestDox('a stream opened by a payload without a request ID is not resumed')]
    public function testStreamWithoutRequestIdDoesNotReconnect(): void
    {
        $clock = new SteppingClock();
        $payload = '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}';
        $httpClient = RecordingHttpClient::queued([
            new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Mcp-Session-Id' => 'session-1',
            ], "id: batch\nretry: 0\ndata: [{$payload}]\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $result = $this->runRequest($transport, 1, '[{"jsonrpc":"2.0","id":1,"method":"tools/call"}]');

        $this->assertInstanceOf(JsonRpcResponse::class, $result);
        $this->assertCount(1, $httpClient->requests);
    }

    #[TestDox('a request times out while its reconnect is still scheduled')]
    public function testRequestTimesOutWhileReconnectIsScheduled(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: resumable\nretry: 3600000\ndata:\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $result = $this->runRequest($transport, 9, timeout: 1);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame('Request timed out', $result->message);
        $this->assertCount(1, $httpClient->requests);
    }

    #[TestDox('receiving the final response before EOF is a normal end and does not reconnect')]
    public function testFinalResponseDoesNotReconnect(): void
    {
        $clock = new SteppingClock();
        $payload = '{"jsonrpc":"2.0","id":3,"result":{"ok":true}}';
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: complete\ndata: {$payload}\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $this->assertInstanceOf(JsonRpcResponse::class, $this->runRequest($transport, 3));
        $this->assertCount(1, $httpClient->requests);
    }

    #[TestDox('closing the transport from a message listener stops reading and reconnecting')]
    public function testCloseFromListenerStopsReconnecting(): void
    {
        $clock = new SteppingClock();
        $notification = '{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"bye"}}';
        $httpClient = RecordingHttpClient::queued([
            new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Mcp-Session-Id' => 'session-1',
            ], "id: resumable\ndata: {$notification}\n\n"),
            new Response(204),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $errors = [];
        $result = $this->runRequest(
            $transport,
            4,
            timeout: 1,
            errors: $errors,
            onMessage: static function () use ($transport): void {
                $transport->close();
            },
        );

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame([], $errors);
        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('DELETE', $httpClient->requests[1]->getMethod());
        $this->assertSame('session-1', $httpClient->requests[1]->getHeaderLine('Mcp-Session-Id'));
    }

    #[TestDox('an unfinished stream without an event ID fails instead of waiting for the request timeout')]
    public function testUnresumableEofFailsPendingRequest(): void
    {
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], ": keep-alive\n\n"),
        ]);
        $transport = $this->createTransport(httpClient: $httpClient, clock: new SteppingClock());

        $result = $this->runRequest($transport, 5);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('did not provide an event ID', $result->message);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function streamCutProvider(): iterable
    {
        foreach (['LF' => "\n", 'CRLF' => "\r\n"] as $name => $eol) {
            $length = \strlen(ReplayingSseServer::stream($eol));

            for ($cut = 0; $cut < $length; ++$cut) {
                yield \sprintf('%s, cut after %d bytes', $name, $cut) => [$eol, $cut];
            }
        }
    }

    #[DataProvider('streamCutProvider')]
    #[TestDox('a stream cut at any byte delivers every message exactly once after resuming ($_dataName)')]
    public function testStreamCutAtAnyByteDeliversEveryMessageOnce(string $eol, int $cut): void
    {
        $transport = $this->createTransport(httpClient: new ReplayingSseServer($eol, $cut), clock: new SteppingClock());

        $messages = [];
        $result = $this->runRequest($transport, ReplayingSseServer::REQUEST_ID, messages: $messages);

        if ($cut < ReplayingSseServer::primingEventLength($eol)) {
            // Nothing complete arrived, so there is no event ID to resume from.
            $this->assertSame([], $messages);
            $this->assertInstanceOf(Error::class, $result);

            return;
        }

        $this->assertSame(ReplayingSseServer::MESSAGES, $messages);
        $this->assertInstanceOf(JsonRpcResponse::class, $result);
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

    public function testCancellationPostExpiryInvalidatesConnectionWithoutReplacingCancellation(): void
    {
        $token = new class implements CancellationTokenInterface {
            public bool $cancelled = false;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $httpClient = new RecordingHttpClient(static function (array $message) use ($token): ?ResponseInterface {
            if ('initialize' === ($message['method'] ?? null)) {
                return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'session'], json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $message['id'],
                    'result' => [
                        'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                        'capabilities' => ['tools' => []],
                        'serverInfo' => ['name' => 'test-server', 'version' => '1'],
                    ],
                ], \JSON_THROW_ON_ERROR));
            }
            if ('notifications/cancelled' === ($message['method'] ?? null)) {
                return new Response(404);
            }
            if ('slow' === ($message['params']['name'] ?? null)) {
                $token->cancelled = true;
            }

            return null;
        });
        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);
        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();
        $client->connect($transport);
        $this->assertTrue($client->isConnected());

        try {
            $client->callTool('slow', cancellation: $token);
            $this->fail('Expected cancellation.');
        } catch (RequestCancelledException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
        }

        $this->assertCount(1, $httpClient->messagesOfMethod('notifications/cancelled'));
        $this->assertNull($transport->getSessionId());
        $this->assertFalse($client->isConnected());
        $client->connect($transport);
        $this->assertCount(2, $httpClient->messagesOfMethod('initialize'));
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

    #[TestDox('a handshake-era connection names its revision on every request after "initialize", until the session expires')]
    public function testHandshakeEraRequestsCarryTheProtocolVersion(): void
    {
        $expired = false;
        $httpClient = new RecordingHttpClient(static function (array $message) use (&$expired): ?ResponseInterface {
            if ('initialize' === ($message['method'] ?? null)) {
                return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'session'], json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $message['id'],
                    'result' => [
                        'protocolVersion' => ProtocolVersion::V2025_06_18->value,
                        'capabilities' => ['tools' => []],
                        'serverInfo' => ['name' => 'test-server', 'version' => '1'],
                    ],
                ], \JSON_THROW_ON_ERROR));
            }
            if ('gone' === ($message['params']['name'] ?? null)) {
                $expired = true;

                return new Response(404);
            }

            return null;
        });
        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);
        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();

        $client->connect($transport);
        try {
            $client->callTool('gone');
            $this->fail('An expired session must fail the call.');
        } catch (ConnectionException) {
        }
        $this->assertTrue($expired);
        $client->connect($transport);
        $client->callTool('fast');
        $client->disconnect();

        $sent = array_map(static function (RequestInterface $request): string {
            $method = json_decode((string) $request->getBody(), true)['method'] ?? $request->getMethod();

            return $method.' '.($request->getHeaderLine('MCP-Protocol-Version') ?: '-');
        }, $httpClient->requests);

        $this->assertSame([
            'initialize -',
            'notifications/initialized 2025-06-18',
            'tools/call 2025-06-18',
            'initialize -',
            'notifications/initialized 2025-06-18',
            'tools/call 2025-06-18',
            'DELETE 2025-06-18',
        ], $sent);
    }

    /**
     * @param array<string, string> $headers
     */
    private function createTransport(
        int $maxSseBufferBytes = 8 * 1024 * 1024,
        ?ClientInterface $httpClient = null,
        array $headers = [],
        ?ClockInterface $clock = null,
    ): HttpTransport {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            headers: $headers,
            httpClient: $httpClient ?? $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            maxSseBufferBytes: $maxSseBufferBytes,
            clock: $clock,
        );
    }

    /**
     * Sends one request through the transport's own loop, the way the client
     * does, and returns what it resolved to.
     *
     * @param list<string>            $messages  receives every message the transport dispatched
     * @param list<\Throwable>        $errors    receives every error the transport reported
     * @param (callable(): void)|null $onMessage called after each dispatched message
     *
     * @return JsonRpcResponse<mixed>|Error
     */
    private function runRequest(
        HttpTransport $transport,
        int $id,
        ?string $payload = null,
        int $timeout = 5,
        array &$messages = [],
        array &$errors = [],
        ?callable $onMessage = null,
        ?ClientState $state = null,
    ): JsonRpcResponse|Error {
        $messages = [];
        $errors = [];
        $state ??= new ClientState();
        $state->addPendingRequest($id, $timeout);
        $transport->setState($state);
        $transport->onError(static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });
        // Stands in for the protocol, which stores each response for the loop to pick up.
        $transport->onMessage(static function (string $message) use ($state, &$messages, $onMessage): void {
            $messages[] = $message;
            $decoded = json_decode($message, true);

            foreach (\is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded] as $item) {
                if (\is_array($item) && isset($item['id']) && (isset($item['result']) || isset($item['error']))) {
                    $state->storeResponse($item['id'], $item);
                }
            }

            if (null !== $onMessage) {
                $onMessage();
            }
        });

        $payload ??= \sprintf('{"jsonrpc":"2.0","id":%d,"method":"tools/call"}', $id);

        return $transport->runRequest(new \Fiber(static function () use ($transport, $payload): JsonRpcResponse|Error {
            $transport->send($payload);

            return \Fiber::suspend();
        }));
    }

    private function assertDelay(int $expectedMs, float $elapsedMs): void
    {
        // The loop notices a due reconnect on the first clock reading at or past it.
        $this->assertGreaterThanOrEqual($expectedMs, $elapsedMs);
        $this->assertLessThanOrEqual($expectedMs + 2 * SteppingClock::STEP_MS, $elapsedMs);
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
 *
 * Built with {@see queued()}, it instead answers each request with the next
 * of a fixed list of responses, for tests that drive the transport directly.
 */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<int> Clock reading, in milliseconds, when each request was sent */
    public array $sentAtMs = [];

    /** @var list<ResponseInterface|\Throwable>|null */
    private ?array $queue = null;

    private ?SteppingClock $clock = null;

    /**
     * The hook sees every request first and may return its own answer; a null
     * return falls through to the default one.
     *
     * @param (\Closure(array<string, mixed>): ?ResponseInterface)|null $intercept
     */
    public function __construct(private readonly ?\Closure $intercept = null)
    {
    }

    /**
     * @param list<ResponseInterface|\Throwable> $responses a throwable is thrown instead of answering
     */
    public static function queued(array $responses, ?SteppingClock $clock = null): self
    {
        $client = new self();
        $client->queue = $responses;
        $client->clock = $clock;

        return $client;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->sentAtMs[] = $this->clock?->milliseconds() ?? 0;

        if (null !== $this->queue) {
            $response = array_shift($this->queue);

            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response ?? throw new \LogicException('No HTTP response was queued for request '.$request->getMethod().'.');
        }

        if ('POST' !== $request->getMethod()) {
            return new Response(202);
        }

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

/**
 * Moves a fixed step forward on every reading, so the transport's loop reaches
 * a scheduled reconnect after a bounded number of ticks instead of real time.
 *
 * @internal
 */
final class SteppingClock implements ClockInterface
{
    public const STEP_MS = 100;

    private int $milliseconds = 0;

    public function now(): \DateTimeImmutable
    {
        $this->milliseconds += self::STEP_MS;

        return new \DateTimeImmutable(\sprintf('@%.3F', $this->milliseconds / 1000));
    }

    /**
     * The latest reading, without moving the clock.
     */
    public function milliseconds(): int
    {
        return $this->milliseconds;
    }
}

/**
 * Serves a fixed SSE stream for one request, cut after a given number of
 * bytes, and replays the events after Last-Event-ID on a resuming GET.
 *
 * @internal
 */
final class ReplayingSseServer implements ClientInterface
{
    public const REQUEST_ID = 7;

    public const MESSAGES = [
        '{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"first"}}',
        '{"jsonrpc":"2.0","method":"notifications/message","params":{"level":"info","data":"second"}}',
        '{"jsonrpc":"2.0","id":7,"result":{"content":[]}}',
    ];

    public function __construct(
        private readonly string $eol,
        private readonly int $cut,
    ) {
    }

    public static function stream(string $eol, int $after = -1): string
    {
        $stream = '';

        foreach (self::events($eol) as $id => $event) {
            if ($id > $after) {
                $stream .= $event;
            }
        }

        return $stream;
    }

    public static function primingEventLength(string $eol): int
    {
        return \strlen(self::events($eol)[0]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ('POST' === $request->getMethod()) {
            return new Response(200, ['Content-Type' => 'text/event-stream', 'Mcp-Session-Id' => 'session-1'], substr(self::stream($this->eol), 0, $this->cut));
        }

        return new Response(200, ['Content-Type' => 'text/event-stream'], self::stream($this->eol, (int) $request->getHeaderLine('Last-Event-ID')));
    }

    /**
     * @return list<string>
     */
    private static function events(string $eol): array
    {
        $events = ['id: 0'.$eol.'retry: 500'.$eol.'data:'.$eol.$eol];

        foreach (self::MESSAGES as $index => $message) {
            $events[] = 'id: '.($index + 1).$eol.'data: '.$message.$eol.$eol;
        }

        return $events;
    }
}
