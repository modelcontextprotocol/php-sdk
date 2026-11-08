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

use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Mcp\Client;
use Mcp\Client\CancellationTokenInterface;
use Mcp\Client\Configuration;
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\RequestException;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

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
        // As if the request that carried the token were in flight.
        (new \ReflectionProperty($protocol, 'progressToken'))->setValue($protocol, 't');

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
        $stream = $this->factory->createStream(str_repeat('a', 4096));
        $this->setActiveStream($transport, $stream);

        $this->invokeProcessSseStream($transport);

        $this->assertSame(0, $this->readPrivate($transport, 'sseParser')->bufferedBytes(), 'buffer must be cleared on abort');
        $this->assertNull($this->readPrivate($transport, 'activeStream'), 'stream must be released on abort');
        $this->assertFalse($stream->isReadable(), 'stream must be closed on abort');
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
        $result = $this->runRequest($transport, 7, '{"jsonrpc":"2.0","id":7,"method":"initialize"}', messages: $messages);

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
        yield 'redirect' => [307, 2];
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

    #[TestDox('a discovered Symfony client keeps its configuration and stops following redirects')]
    public function testDiscoveredClientKeepsItsConfiguration(): void
    {
        if (!(new \ReflectionClass(Psr18Client::class))->hasMethod('withOptions')) {
            $this->markTestSkipped('Psr18Client has no withOptions() before symfony/http-client 6.2.');
        }

        $maxRedirects = null;
        $mock = new MockHttpClient(static function (string $method, string $url, array $options) use (&$maxRedirects): MockResponse {
            $maxRedirects = $options['max_redirects'];

            return new MockResponse('', ['http_code' => 202]);
        });

        $strategy = new class implements DiscoveryStrategy {
            public static ClientInterface $client;

            public static function getCandidates($type): array
            {
                return ClientInterface::class === $type ? [['class' => static fn (): ClientInterface => self::$client, 'condition' => true]] : [];
            }
        };
        $strategy::$client = new Psr18Client($mock);

        $strategies = [...Psr18ClientDiscovery::getStrategies()];
        Psr18ClientDiscovery::prependStrategy($strategy::class);

        try {
            $transport = new HttpTransport('http://localhost/mcp', requestFactory: $this->factory, streamFactory: $this->factory);
        } finally {
            Psr18ClientDiscovery::setStrategies($strategies);
        }

        $transport->send('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $this->assertSame(0, $maxRedirects);
    }

    #[TestDox('a reconnect answered with a redirect names its status and target')]
    public function testReconnectRedirectIsNamed(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n"),
            new Response(307, ['Location' => 'https://other.test/mcp']),
        ], $clock);

        $result = $this->runRequest($this->createTransport(httpClient: $httpClient, clock: $clock), 1);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('HTTP 307', $result->message);
        $this->assertStringContainsString('https://other.test/mcp', $result->message);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, string}>
     */
    public static function redirectProvider(): iterable
    {
        foreach ([301, 302, 303, 307, 308] as $status) {
            yield \sprintf('HTTP %d', $status) => [$status, ['Location' => 'https://other.test/mcp'], ''];
        }

        yield 'a body carrying the answer' => [307, ['Location' => 'https://other.test/mcp', 'Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":%d,"result":{"content":[]}}'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('redirectProvider')]
    #[TestDox('a redirect fails the call at once, naming status and target: $_dataName')]
    public function testRedirectFailsTheCallAtOnce(int $status, array $headers, string $body): void
    {
        $httpClient = new RecordingHttpClient(static function (array $message) use ($status, $headers, $body): ?ResponseInterface {
            if ('moved' !== ($message['params']['name'] ?? null)) {
                return null;
            }

            return new Response($status, $headers, \sprintf($body, $message['id']));
        });

        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setRequestTimeout(30)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        try {
            $client->callTool('moved');
            $this->fail('Expected the call to fail.');
        } catch (RequestException $e) {
            $this->assertStringContainsString(\sprintf('HTTP %d', $status), $e->getMessage());
            $this->assertStringContainsString('https://other.test/mcp', $e->getMessage());
        }

        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    /**
     * @return iterable<string, array{ProtocolVersion, ?ProtocolVersion, string}>
     */
    public static function connectRedirectProvider(): iterable
    {
        yield 'the probe, with a fallback' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_11_25, 'server/discover'];
        yield 'the probe, without a fallback' => [ProtocolVersion::V2026_07_28, null, 'server/discover'];
        yield 'the handshake' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25, 'initialize'];
    }

    #[DataProvider('connectRedirectProvider')]
    #[TestDox('a redirect fails connect() at once, without a retry or a fallback: $_dataName')]
    public function testRedirectFailsConnect(ProtocolVersion $version, ?ProtocolVersion $fallback, string $redirectedMethod): void
    {
        $httpClient = new RecordingHttpClient(static function (array $message) use ($redirectedMethod): ResponseInterface {
            if ($redirectedMethod === ($message['method'] ?? null)) {
                return new Response(307, ['Location' => 'https://other.test/mcp']);
            }

            if ('initialize' !== ($message['method'] ?? null)) {
                return new Response(202);
            }

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'serverInfo' => ['name' => 'legacy-server', 'version' => '1.0.0'],
            ]]));
        });

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion($version)
            ->setFallbackProtocolVersion($fallback)
            ->setInitTimeout(5)
            ->build();

        try {
            $client->connect($this->createTransport(httpClient: $httpClient));
            $this->fail('connect() must not succeed on a redirect.');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('Initialization failed: Server answered with HTTP 307 to https://other.test/mcp', $e->getMessage());
        }

        $this->assertSame([$redirectedMethod], array_column($httpClient->messages, 'method'));
    }

    /**
     * @return iterable<string, array{ProtocolVersion}>
     */
    public static function connectResumptionProvider(): iterable
    {
        yield 'the probe' => [ProtocolVersion::V2026_07_28];
        yield 'the handshake' => [ProtocolVersion::V2025_11_25];
    }

    #[DataProvider('connectResumptionProvider')]
    #[TestDox('a redirected resumption fails connect() at once, without a retry or a fallback: $_dataName')]
    public function testRedirectedResumptionFailsConnect(ProtocolVersion $version): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n"),
            new Response(307, ['Location' => 'https://other.test/mcp']),
        ], $clock);

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion($version)
            ->setFallbackProtocolVersion(ProtocolVersion::V2025_11_25)
            ->setInitTimeout(5)
            ->build();

        try {
            $client->connect($this->createTransport(httpClient: $httpClient, clock: $clock));
            $this->fail('connect() must not succeed on a redirect.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('HTTP 307 to https://other.test/mcp', $e->getMessage());
        }

        $this->assertSame(['POST', 'GET'], array_map(static fn (RequestInterface $request): string => $request->getMethod(), $httpClient->requests));
    }

    #[TestDox('a redirect without a Location header still names its status')]
    public function testRedirectWithoutLocationNamesTheStatus(): void
    {
        $httpClient = RecordingHttpClient::queued([new Response(304)]);

        $result = $this->runRequest($this->createTransport(httpClient: $httpClient), 1);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('HTTP 304', $result->message);
    }

    #[TestDox('a redirect of a notification is reported, as nobody waits for its answer')]
    public function testRedirectOfNotificationIsReported(): void
    {
        $transport = $this->createTransport(httpClient: RecordingHttpClient::queued([new Response(307, ['Location' => 'https://other.test/mcp'])]));

        $errors = [];
        $transport->onError(static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });

        $transport->send('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(ConnectionException::class, $errors[0]);
        $this->assertStringContainsString('HTTP 307', $errors[0]->getMessage());
        $this->assertStringContainsString('https://other.test/mcp', $errors[0]->getMessage());
    }

    #[TestDox('a redirect of the listening stream is reported and the connection carries on')]
    public function testRedirectOfListenStreamIsReported(): void
    {
        $transport = new HttpTransport(
            'https://example.test/mcp',
            httpClient: RecordingHttpClient::queued([new Response(307, ['Location' => 'https://other.test/mcp'])]),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            listen: true,
        );

        $errors = [];
        $transport->onError(static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });
        $transport->onInitialize(static fn () => null);

        $transport->connect();

        $this->assertCount(1, $errors);
        $this->assertInstanceOf(ConnectionException::class, $errors[0]);
        $this->assertStringContainsString('HTTP 307', $errors[0]->getMessage());
        $this->assertStringContainsString('https://other.test/mcp', $errors[0]->getMessage());
    }

    #[TestDox('a handshake-era session ID stays the one from the initialize response')]
    public function testLaterResponseDoesNotReplaceTheSessionId(): void
    {
        $httpClient = new RecordingHttpClient(static function (array $message): ResponseInterface {
            $sessionId = 'initialize' === ($message['method'] ?? null) ? 'session' : 'replacement';
            $result = 'initialize' === ($message['method'] ?? null)
                ? ['protocolVersion' => ProtocolVersion::V2025_11_25->value, 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'test-server', 'version' => '1']]
                : ['content' => []];

            return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'] ?? null, 'result' => $result], \JSON_THROW_ON_ERROR));
        });
        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);
        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();

        $client->connect($transport);
        $client->callTool('first');
        $this->assertSame('session', $transport->getSessionId());

        $client->callTool('second');
        $client->disconnect();

        $this->assertSame(['session'], array_values(array_unique(array_map(
            static fn (RequestInterface $request): string => $request->getHeaderLine('Mcp-Session-Id'),
            \array_slice($httpClient->requests, 1),
        ))));
    }

    #[TestDox('a session ID is ignored on 2026-07-28, so nothing carries it and close sends no DELETE')]
    public function testModernResponseDoesNotSetASessionId(): void
    {
        $httpClient = new RecordingHttpClient(static function (array $message): ResponseInterface {
            $result = 'server/discover' === ($message['method'] ?? null)
                ? ['resultType' => 'complete', 'supportedVersions' => [ProtocolVersion::V2026_07_28->value], 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'test-server', 'version' => '1']]
                : ['resultType' => 'complete', 'content' => []];

            return new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'stray'], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'] ?? null, 'result' => $result], \JSON_THROW_ON_ERROR));
        });
        $transport = new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory);
        $client = Client::builder()->setClientInfo('test', '1')->build();

        $client->connect($transport);
        $client->callTool('first');
        $this->assertNull($transport->getSessionId());

        $client->callTool('second');
        $client->disconnect();

        $this->assertSame(['POST'], array_values(array_unique(array_map(static fn (RequestInterface $request): string => $request->getMethod(), $httpClient->requests))));
        foreach ($httpClient->requests as $request) {
            $this->assertFalse($request->hasHeader('Mcp-Session-Id'));
        }
    }

    #[TestDox('an initialize refused with an error status sets no session ID')]
    public function testRefusedInitializeDoesNotSetASessionId(): void
    {
        $transport = $this->createTransport(httpClient: RecordingHttpClient::queued([new Response(500, ['Mcp-Session-Id' => 'session'])]));

        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');

        $this->assertNull($transport->getSessionId());
    }

    #[TestDox('a redirect of the closing DELETE is reported and the session is forgotten')]
    public function testRedirectOfCloseIsReported(): void
    {
        $transport = $this->createTransport(httpClient: RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'session'], '{"jsonrpc":"2.0","id":1,"result":{}}'),
            new Response(307, ['Location' => 'https://other.test/mcp']),
        ]));

        $errors = [];
        $transport->onError(static function (\Throwable $error) use (&$errors): void {
            $errors[] = $error;
        });

        $transport->send('{"jsonrpc":"2.0","id":1,"method":"initialize"}');
        $this->assertSame('session', $transport->getSessionId());

        $transport->close();

        $this->assertNull($transport->getSessionId());
        $this->assertCount(1, $errors);
        $this->assertInstanceOf(ConnectionException::class, $errors[0]);
        $this->assertStringContainsString('HTTP 307', $errors[0]->getMessage());
        $this->assertStringContainsString('https://other.test/mcp', $errors[0]->getMessage());
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

        $result = $this->runRequest($transport, 1, '{"jsonrpc":"2.0","id":1,"method":"initialize"}', state: $state);

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
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects($this->once())->method('close');
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
            ], "id: resumable\nretry: 0\ndata: {$payload}\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $result = $this->runRequest($transport, 1, '{"jsonrpc":"2.0","id":1,"result":{}}');

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

    #[TestDox('a malformed answer to the request does not complete its stream')]
    public function testMalformedAnswerDoesNotCompleteStream(): void
    {
        $clock = new SteppingClock();
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata: {\"jsonrpc\":\"2.0\",\"id\":3,\"result\":null}\n\n"),
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-1\ndata: {\"jsonrpc\":\"2.0\",\"id\":3,\"result\":{}}\n\n"),
        ], $clock);
        $transport = $this->createTransport(httpClient: $httpClient, clock: $clock);

        $this->assertInstanceOf(JsonRpcResponse::class, $this->runRequest($transport, 3, timeout: 1));
        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('event-0', $httpClient->requests[1]->getHeaderLine('Last-Event-ID'));
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
            '{"jsonrpc":"2.0","id":4,"method":"initialize"}',
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
        $stream = $this->factory->createStream(": keep-alive\n\n");
        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], $stream),
        ]);
        $transport = $this->createTransport(httpClient: $httpClient, clock: new SteppingClock());

        $result = $this->runRequest($transport, 5);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('did not provide an event ID', $result->message);
        $this->assertFalse($stream->isReadable(), 'the ended stream must be closed');
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

        // A CR ends a line on its own, so the first byte of the blank line completes the event.
        if ($cut < ReplayingSseServer::primingEventLength($eol) - \strlen($eol) + 1) {
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

    public function testCancellationNotificationIsSentBeforeTheStreamIsRead(): void
    {
        $httpClient = RecordingHttpClient::queued([new Response(202)]);
        $transport = $this->createTransport(httpClient: $httpClient);
        $state = new ClientState();
        $transport->setState($state);
        $state->addPendingRequest(1, 30);
        $body = $this->createMock(StreamInterface::class);
        $body->expects($this->never())->method('read');
        $body->expects($this->once())->method('close');
        $this->setActiveStream($transport, $body);

        // Like Protocol::exchange(), the fiber notifies the server before it rethrows.
        $fiber = new \Fiber(static function () use ($transport): never {
            try {
                \Fiber::suspend([
                    'type' => 'await_response',
                    'request_id' => 1,
                    'timeout' => 30,
                    'deadline' => microtime(true) - 1,
                ]);
            } catch (TimeoutException $e) {
                $transport->send('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}');

                throw $e;
            }

            throw new \LogicException('The deadline did not interrupt the request.');
        });

        try {
            $transport->runRequest($fiber);
            $this->fail('Expected a timeout.');
        } catch (TimeoutException) {
        }

        $this->assertCount(1, $httpClient->requests);
        $this->assertStringContainsString('notifications/cancelled', (string) $httpClient->requests[0]->getBody());
    }

    #[TestDox('an initialize stream the server leaves open is not read again once "initialized" went out')]
    public function testInitializeStreamIsNotReadAfterHandshake(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->expects($this->once())->method('read')->willReturn('data: '.json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'serverInfo' => ['name' => 'test', 'version' => '1'],
            ],
        ], \JSON_THROW_ON_ERROR)."\n\n");

        $httpClient = RecordingHttpClient::queued([
            new Response(200, ['Content-Type' => 'text/event-stream'], $body),
            new Response(202),
        ]);

        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $this->assertTrue($client->isConnected());
        $this->assertSame('notifications/initialized', json_decode((string) $httpClient->requests[1]->getBody(), true, flags: \JSON_THROW_ON_ERROR)['method'] ?? null);
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
        $answer->expects($this->never())->method('read');

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
        $stream->method('read')->willReturnCallback(static function () use ($body, $token): string {
            $token->cancelled = true;
            $contents = $body->contents;
            $body->contents = '';

            return $contents;
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

    /**
     * A null body is one whose read fails, like a connection reset.
     *
     * @return iterable<string, array{string, ?string}>
     */
    public static function unansweredResponseProvider(): iterable
    {
        yield 'a truncated JSON body' => ['application/json', '{"jsonrpc":"2.0","id":%d,"result":{"cont'];
        yield 'an empty JSON body' => ['application/json', ''];
        yield 'a JSON body failing to read' => ['application/json', null];
        yield 'a malformed JSON answer' => ['application/json', '{"jsonrpc":"2.0","id":%d,"result":"done"}'];
        yield 'a body that is no JSON' => ['text/html', '<html>Gateway says hi</html>'];
        yield 'an answer not sent as JSON' => ['text/plain', '{"jsonrpc":"2.0","id":%d,"result":{"content":[]}}'];
        yield 'no content type and no body' => ['', ''];
    }

    #[TestDox('an error status whose body fails to read still fails the call at once')]
    public function testUnreadableErrorBodyFailsTheCallAtOnce(): void
    {
        $httpClient = new RecordingHttpClient(function (array $message): ?ResponseInterface {
            if ('cut' !== ($message['params']['name'] ?? null)) {
                return null;
            }

            $stream = $this->createStub(StreamInterface::class);
            $stream->method('read')->willThrowException(new \RuntimeException('Connection reset by peer'));

            return new Response(500, ['Content-Type' => 'application/json'], $stream);
        });

        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setRequestTimeout(30)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        try {
            $client->callTool('cut');
            $this->fail('Expected the call to fail.');
        } catch (RequestException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function successContentTypeProvider(): iterable
    {
        yield 'a JSON body' => ['application/json'];
        yield 'a plain text body' => ['text/plain'];
    }

    #[DataProvider('successContentTypeProvider')]
    #[TestDox('a successful response over the body limit fails the request without reading on: $_dataName')]
    public function testSuccessBodyOverTheLimitFailsTheRequest(string $contentType): void
    {
        $body = new OversizedStream(1024 * 1024);
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(200, ['Content-Type' => $contentType], $body)]),
            maxResponseBodyBytes: 1024,
        );

        $messages = [];
        $result = $this->runRequest($transport, 1, messages: $messages);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(Error::INTERNAL_ERROR, $result->code);
        $this->assertStringContainsString('1024 byte limit', $result->message);
        $this->assertStringContainsString('maxResponseBodyBytes', $result->message);
        $this->assertStringNotContainsString('ended', $result->message);
        $this->assertSame([TransportInterface::RESPONSE_TOO_LARGE => true], $result->data);
        $this->assertSame([], $messages);
        $this->assertLessThanOrEqual(1025, $body->bytesRead);
        $this->assertTrue($body->closed);
    }

    #[TestDox('the default body limit applies without any configuration')]
    public function testDefaultBodyLimit(): void
    {
        $body = new OversizedStream(2 * HttpTransport::DEFAULT_MAX_RESPONSE_BODY_BYTES);
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(200, ['Content-Type' => 'application/json'], $body)]),
        );

        $result = $this->runRequest($transport, 1);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertLessThanOrEqual(HttpTransport::DEFAULT_MAX_RESPONSE_BODY_BYTES + 1, $body->bytesRead);
    }

    #[TestDox('a body of exactly the limit is read whole, across several reads')]
    public function testBodyAtTheLimitIsDispatched(): void
    {
        $answer = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['pad' => str_repeat('a', 20000)]], \JSON_THROW_ON_ERROR);
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(200, ['Content-Type' => 'application/json'], $answer)]),
            maxResponseBodyBytes: \strlen($answer),
        );

        $messages = [];
        $result = $this->runRequest($transport, 1, messages: $messages);

        $this->assertInstanceOf(JsonRpcResponse::class, $result);
        $this->assertSame([$answer], $messages);
    }

    #[TestDox('a body one byte over the limit fails the request')]
    public function testBodyOneByteOverTheLimitFailsTheRequest(): void
    {
        $answer = '{"jsonrpc":"2.0","id":1,"result":{}}';
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(200, ['Content-Type' => 'application/json'], $answer)]),
            maxResponseBodyBytes: \strlen($answer) - 1,
        );

        $messages = [];
        $result = $this->runRequest($transport, 1, messages: $messages);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame([], $messages);
    }

    #[TestDox('an error status over the body limit still reports the status, without reading on')]
    public function testErrorBodyOverTheLimitReportsTheStatus(): void
    {
        $body = new OversizedStream(1024 * 1024);
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(500, ['Content-Type' => 'text/html'], $body)]),
            maxResponseBodyBytes: 1024,
        );

        $result = $this->runRequest($transport, 1);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('HTTP 500', $result->message);
        $this->assertLessThanOrEqual(1025, $body->bytesRead);
        $this->assertTrue($body->closed);
    }

    #[TestDox('an error status over the body limit logs the body cut at the limit')]
    public function testErrorBodyOverTheLimitIsLoggedTruncated(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Server refused a message',
            $this->callback(static fn (array $context): bool => 1024 === \strlen($context['body'])),
        );

        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(500, ['Content-Type' => 'text/html'], new OversizedStream(1024 * 1024))]),
            maxResponseBodyBytes: 1024,
            logger: $logger,
        );

        $transport->send('{"jsonrpc":"2.0","id":1,"result":{}}');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function loggedBodyLimitProvider(): iterable
    {
        yield 'cut by the log cap' => [HttpTransport::DEFAULT_MAX_RESPONSE_BODY_BYTES, 4096];
        yield 'cut by the body limit' => [1024, 1024];
    }

    #[DataProvider('loggedBodyLimitProvider')]
    #[TestDox('a refused message logs a capped body without splitting a character: $_dataName')]
    public function testLoggedErrorBodyIsCappedOnACharacterBoundary(int $maxResponseBodyBytes, int $expectedCap): void
    {
        $logged = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'Server refused a message',
            $this->callback(static function (array $context) use (&$logged): bool {
                $logged = $context['body'];

                return true;
            }),
        );

        // The odd prefix makes a cut at an even byte count fall inside a two-byte character.
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(500, ['Content-Type' => 'text/html'], 'a'.str_repeat('é', 3000))]),
            maxResponseBodyBytes: $maxResponseBodyBytes,
            logger: $logger,
        );

        $transport->send('{"jsonrpc":"2.0","id":1,"result":{}}');

        $this->assertIsString($logged);
        $this->assertLessThanOrEqual($expectedCap, \strlen($logged));
        $this->assertGreaterThan($expectedCap - 6, \strlen($logged));
        $this->assertSame(1, preg_match('//u', $logged));
    }

    #[TestDox('an error status body cut at the limit is not read as an answer, even when the cut part is valid JSON')]
    public function testCutErrorBodyIsNotDispatched(): void
    {
        $answer = '{"jsonrpc":"2.0","id":1,"result":{}}';
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(500, ['Content-Type' => 'application/json'], $answer.'garbage')]),
            maxResponseBodyBytes: \strlen($answer),
        );

        $messages = [];
        $result = $this->runRequest($transport, 1, messages: $messages);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('HTTP 500', $result->message);
        $this->assertSame([], $messages);
    }

    #[TestDox('an error status body of exactly the limit is still read as an answer')]
    public function testErrorBodyAtTheLimitIsDispatched(): void
    {
        $answer = '{"jsonrpc":"2.0","id":1,"error":{"code":-32000,"message":"Refused"}}';
        $transport = $this->createTransport(
            httpClient: RecordingHttpClient::queued([new Response(500, ['Content-Type' => 'application/json'], $answer)]),
            maxResponseBodyBytes: \strlen($answer),
        );

        $messages = [];
        $result = $this->runRequest($transport, 1, messages: $messages);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame('Refused', $result->message);
        $this->assertSame([$answer], $messages);
    }

    /**
     * @return iterable<string, array{ProtocolVersion, ?ProtocolVersion, string}>
     */
    public static function connectBodyLimitProvider(): iterable
    {
        yield 'the probe, with a fallback' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_11_25, 'server/discover'];
        yield 'the probe, without a fallback' => [ProtocolVersion::V2026_07_28, null, 'server/discover'];
        yield 'the handshake' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25, 'initialize'];
    }

    #[DataProvider('connectBodyLimitProvider')]
    #[TestDox('a response over the body limit fails connect() at once, without a retry or a fallback: $_dataName')]
    public function testBodyOverTheLimitFailsConnect(ProtocolVersion $version, ?ProtocolVersion $fallback, string $oversizedMethod): void
    {
        $httpClient = new RecordingHttpClient(static function (array $message) use ($oversizedMethod): ResponseInterface {
            if ($oversizedMethod === ($message['method'] ?? null)) {
                return new Response(200, ['Content-Type' => 'application/json'], new OversizedStream(1024 * 1024));
            }

            if ('initialize' !== ($message['method'] ?? null)) {
                return new Response(202);
            }

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => [
                'protocolVersion' => ProtocolVersion::V2025_11_25->value,
                'capabilities' => new \stdClass(),
                'serverInfo' => ['name' => 'legacy-server', 'version' => '1.0.0'],
            ]]));
        });

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setProtocolVersion($version)
            ->setFallbackProtocolVersion($fallback)
            ->setInitTimeout(5)
            ->build();

        try {
            $client->connect($this->createTransport(httpClient: $httpClient, maxResponseBodyBytes: 1024));
            $this->fail('connect() must not succeed on a response over the limit.');
        } catch (ConnectionException $e) {
            $this->assertStringStartsWith('Initialization failed: The response body exceeds the 1024 byte limit.', $e->getMessage());
        }

        $this->assertSame([$oversizedMethod], array_column($httpClient->messages, 'method'));
    }

    #[TestDox('the response body limit must be a positive number of bytes')]
    public function testRejectsNonPositiveBodyLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(maxResponseBodyBytes: 0);
    }

    #[DataProvider('unansweredResponseProvider')]
    #[TestDox('a response that ends without the answer fails the call at once: $_dataName')]
    public function testUnansweredResponseFailsTheCallAtOnce(string $contentType, ?string $body): void
    {
        $httpClient = new RecordingHttpClient(function (array $message) use ($contentType, $body): ?ResponseInterface {
            if ('cut' !== ($message['params']['name'] ?? null)) {
                return null;
            }

            $headers = '' === $contentType ? [] : ['Content-Type' => $contentType];
            if (null !== $body) {
                return new Response(200, $headers, \sprintf($body, $message['id']));
            }

            $stream = $this->createStub(StreamInterface::class);
            $stream->method('read')->willThrowException(new \RuntimeException('Connection reset by peer'));

            return new Response(200, $headers, $stream);
        });

        $client = Client::builder()->setClientInfo('test', '1')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setRequestTimeout(30)->build();
        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $started = microtime(true);
        try {
            $client->callTool('cut');
            $this->fail('Expected the call to fail.');
        } catch (RequestException $e) {
            $this->assertStringContainsString('without answering the request', $e->getMessage());
        }

        $this->assertLessThan(1, microtime(true) - $started, 'the cut must not be waited out like a slow answer');
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

    #[TestDox('an HTTP client that suspends the calling fiber, as amphp does, is not called inside the request fiber')]
    public function testHttpClientIsCalledOutsideTheRequestFiber(): void
    {
        $httpClient = new class(new RecordingHttpClient()) implements ClientInterface {
            public function __construct(private readonly RecordingHttpClient $server)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                FiberSuspendingStream::awaitEventLoop();

                $response = $this->server->sendRequest($request);

                // The body of an amphp response is read through the event loop too.
                return $response->withBody(new FiberSuspendingStream($response->getBody()));
            }
        };

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setInitTimeout(1)
            ->setMaxRetries(0)
            ->build();

        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

        $this->assertTrue($client->isConnected());
        $this->assertSame('test-server', $client->getServerInfo()?->name);
        $this->assertSame('next call', $client->callTool('fast')->content[0]->text ?? null);
        $client->disconnect();
    }

    /**
     * @param array<string, string> $headers
     */
    private function createTransport(
        int $maxSseBufferBytes = 8 * 1024 * 1024,
        ?ClientInterface $httpClient = null,
        array $headers = [],
        ?ClockInterface $clock = null,
        int $maxResponseBodyBytes = HttpTransport::DEFAULT_MAX_RESPONSE_BODY_BYTES,
        ?LoggerInterface $logger = null,
    ): HttpTransport {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            headers: $headers,
            httpClient: $httpClient ?? $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            logger: $logger,
            maxSseBufferBytes: $maxSseBufferBytes,
            clock: $clock,
            maxResponseBodyBytes: $maxResponseBodyBytes,
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

/**
 * A response body that, like one read through Revolt's event loop, suspends the
 * fiber it is read from and expects only that loop to resume it.
 */
final class FiberSuspendingStream implements StreamInterface
{
    public function __construct(private readonly StreamInterface $inner)
    {
    }

    /**
     * Inside a fiber, a Revolt-based client suspends and waits for its event loop
     * to resume it; only from {main} does it run the loop itself and carry on.
     */
    public static function awaitEventLoop(): void
    {
        if (null === \Fiber::getCurrent()) {
            return;
        }

        $resumedWith = \Fiber::suspend();

        throw new \LogicException(\sprintf('Resumed by something other than the event loop, with %s.', get_debug_type($resumedWith)));
    }

    public function __toString(): string
    {
        self::awaitEventLoop();

        return (string) $this->inner;
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \LogicException('A response body is not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        self::awaitEventLoop();

        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        self::awaitEventLoop();

        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}

/**
 * A response body larger than any limit under test, counting what was read from it.
 */
final class OversizedStream implements StreamInterface
{
    public int $bytesRead = 0;

    public bool $closed = false;

    public function __construct(private readonly int $size)
    {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->bytesRead;
    }

    public function eof(): bool
    {
        return $this->closed || $this->bytesRead >= $this->size;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \LogicException('This body is not seekable.');
    }

    public function rewind(): void
    {
        throw new \LogicException('This body is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \LogicException('A response body is not writable.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        $length = min($length, $this->size - $this->bytesRead);
        $this->bytesRead += $length;

        return str_repeat('a', $length);
    }

    public function getContents(): string
    {
        return $this->read($this->size);
    }

    public function getMetadata(?string $key = null): mixed
    {
        return null === $key ? [] : null;
    }
}
