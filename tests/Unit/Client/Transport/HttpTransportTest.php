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
use Mcp\Client\State\ClientState;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
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

        $client = Client::builder()
            ->setClientInfo('test-client', '1.0.0')
            ->setInitTimeout(1)
            ->build();

        $client->connect(new HttpTransport('http://localhost/mcp', [], $httpClient, $this->factory, $this->factory));

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

    #[TestDox('an interrupted SSE request resumes with its latest event ID after the server retry delay')]
    public function testReconnectsWithLatestEventIdAfterServerDelay(): void
    {
        $now = 1_000.0;
        $response = json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'result' => ['ok' => true],
        ], \JSON_THROW_ON_ERROR);
        $httpClient = new RecordingHttpClient([
            new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Mcp-Session-Id' => 'session-1',
            ], "id: first\ndata:\n\nid: latest\nretry: 500\ndata:\n\n"),
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: final\ndata: {$response}\n\n"),
        ]);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            headers: [
                'Authorization' => 'Bearer secret',
                'MCP-Protocol-Version' => '2025-11-25',
            ],
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $state = new ClientState();
        $state->addPendingRequest(7, 30);
        $transport->setState($state);
        $messages = [];
        $transport->onMessage(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $transport->send(json_encode([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
        ], \JSON_THROW_ON_ERROR));
        $this->invokeProcessSseStream($transport);

        $this->assertSame(1_500.0, $this->readPrivate($transport, 'nextReconnectAtMs'));

        $this->invokeProcessSseReconnect($transport);
        $this->assertCount(1, $httpClient->requests);

        $now = 1_500.0;
        $this->invokeProcessSseReconnect($transport);

        $this->assertCount(2, $httpClient->requests);
        $request = $httpClient->requests[1];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('text/event-stream', $request->getHeaderLine('Accept'));
        $this->assertSame('session-1', $request->getHeaderLine('Mcp-Session-Id'));
        $this->assertSame('latest', $request->getHeaderLine('Last-Event-ID'));
        $this->assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        $this->assertSame('2025-11-25', $request->getHeaderLine('MCP-Protocol-Version'));

        $this->invokeProcessSseStream($transport);

        $this->assertSame([$response], $messages);
        $this->assertNull($this->readPrivate($transport, 'nextReconnectAtMs'));
    }

    #[TestDox('fallback reconnect delays double, cap at ten seconds, and stop after five attempts')]
    public function testReconnectBackoffAndAttemptLimit(): void
    {
        $now = 0.0;
        $responses = [new Response(200, ['Content-Type' => 'text/event-stream'], "id: event-0\ndata:\n\n")];

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $responses[] = new Response(503);
        }

        $httpClient = new RecordingHttpClient($responses);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $state = new ClientState();
        $state->addPendingRequest(1, 60);
        $transport->setState($state);
        $transport->send('{"jsonrpc":"2.0","id":1,"method":"tools/call"}');

        $this->invokeProcessSseStream($transport);
        foreach ([1_000, 2_000, 4_000, 8_000, 10_000] as $attempt => $delay) {
            $this->assertSame($now + $delay, $this->readPrivate($transport, 'nextReconnectAtMs'));
            $now += $delay;
            $this->invokeProcessSseReconnect($transport);

            $request = $httpClient->requests[$attempt + 1];
            $this->assertSame('GET', $request->getMethod());
            $this->assertSame('event-0', $request->getHeaderLine('Last-Event-ID'));
        }

        $this->assertCount(6, $httpClient->requests, 'one POST plus exactly five reconnect attempts');
        $error = $state->consumeResponse(1);
        $this->assertInstanceOf(Error::class, $error);
        $this->assertStringContainsString('maximum of 5 reconnect attempts', $error->message);
    }

    #[TestDox('a request that is no longer pending cancels its scheduled reconnect')]
    public function testCompletedOrCancelledRequestDoesNotReconnect(): void
    {
        $now = 0.0;
        $httpClient = new RecordingHttpClient([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: resumable\ndata:\n\n"),
        ]);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $state = new ClientState();
        $state->addPendingRequest(9, 30);
        $transport->setState($state);
        $transport->send('{"jsonrpc":"2.0","id":9,"method":"tools/call"}');
        $this->invokeProcessSseStream($transport);

        $state->removePendingRequest(9);
        $now = 10_000.0;
        $this->invokeProcessSseReconnect($transport);

        $this->assertCount(1, $httpClient->requests);
        $this->assertNull($this->readPrivate($transport, 'nextReconnectAtMs'));
    }

    #[TestDox('receiving the final response before EOF is a normal end and does not reconnect')]
    public function testFinalResponseDoesNotReconnect(): void
    {
        $now = 0.0;
        $payload = '{"jsonrpc":"2.0","id":3,"result":{"ok":true}}';
        $httpClient = new RecordingHttpClient([
            new Response(200, ['Content-Type' => 'text/event-stream'], "id: complete\ndata: {$payload}\n\n"),
        ]);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $state = new ClientState();
        $state->addPendingRequest(3, 30);
        $transport->setState($state);
        $transport->send('{"jsonrpc":"2.0","id":3,"method":"tools/call"}');

        $this->invokeProcessSseStream($transport);
        $now = 60_000.0;
        $this->invokeProcessSseReconnect($transport);

        $this->assertCount(1, $httpClient->requests);
        $this->assertNull($this->readPrivate($transport, 'nextReconnectAtMs'));
    }

    #[TestDox('closing the transport cancels a scheduled reconnect')]
    public function testCloseCancelsScheduledReconnect(): void
    {
        $now = 0.0;
        $httpClient = new RecordingHttpClient([
            new Response(200, [
                'Content-Type' => 'text/event-stream',
                'Mcp-Session-Id' => 'session-1',
            ], "id: resumable\ndata:\n\n"),
            new Response(204),
        ]);
        $transport = $this->createTransport(
            httpClient: $httpClient,
            clock: static function () use (&$now): float {
                return $now;
            },
        );
        $state = new ClientState();
        $state->addPendingRequest(4, 30);
        $transport->setState($state);
        $transport->send('{"jsonrpc":"2.0","id":4,"method":"tools/call"}');
        $this->invokeProcessSseStream($transport);

        $transport->close();
        $now = 60_000.0;
        $this->invokeProcessSseReconnect($transport);

        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('DELETE', $httpClient->requests[1]->getMethod());
        $this->assertSame('session-1', $httpClient->requests[1]->getHeaderLine('Mcp-Session-Id'));
    }

    #[TestDox('an unfinished stream without an event ID fails instead of waiting for the request timeout')]
    public function testUnresumableEofFailsPendingRequest(): void
    {
        $httpClient = new RecordingHttpClient([
            new Response(200, ['Content-Type' => 'text/event-stream'], ": keep-alive\n\n"),
        ]);
        $transport = $this->createTransport(httpClient: $httpClient);
        $state = new ClientState();
        $state->addPendingRequest(5, 30);
        $transport->setState($state);
        $transport->send('{"jsonrpc":"2.0","id":5,"method":"tools/call"}');

        $this->invokeProcessSseStream($transport);

        $error = $state->consumeResponse(5);
        $this->assertInstanceOf(Error::class, $error);
        $this->assertStringContainsString('did not provide an event ID', $error->message);
    }

    #[TestDox('the buffer cap must be a positive number of bytes')]
    public function testRejectsNonPositiveCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(maxSseBufferBytes: 0);
    }

    #[TestDox('the reconnect policy rejects a negative attempt count')]
    public function testRejectsNegativeReconnectAttempts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(maxReconnectAttempts: -1);
    }

    #[TestDox('the maximum reconnect delay cannot be lower than the initial delay')]
    public function testRejectsReconnectDelayCapBelowInitialDelay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createTransport(initialReconnectDelayMs: 1_001, maxReconnectDelayMs: 1_000);
    }

    /**
     * @param array<string, string>    $headers
     * @param (callable(): float)|null $clock
     */
    private function createTransport(
        int $maxSseBufferBytes = 8 * 1024 * 1024,
        ?ClientInterface $httpClient = null,
        array $headers = [],
        int $maxReconnectAttempts = 5,
        int $initialReconnectDelayMs = 1_000,
        int $maxReconnectDelayMs = 10_000,
        ?callable $clock = null,
    ): HttpTransport {
        return new HttpTransport(
            endpoint: 'https://example.test/mcp',
            headers: $headers,
            httpClient: $httpClient ?? $this->createMock(ClientInterface::class),
            requestFactory: $this->factory,
            streamFactory: $this->factory,
            maxSseBufferBytes: $maxSseBufferBytes,
            maxReconnectAttempts: $maxReconnectAttempts,
            initialReconnectDelayMs: $initialReconnectDelayMs,
            maxReconnectDelayMs: $maxReconnectDelayMs,
            clock: $clock,
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

    private function invokeProcessSseReconnect(HttpTransport $transport): void
    {
        (new \ReflectionMethod($transport, 'processSseReconnect'))->invoke($transport);
    }

    private function readPrivate(HttpTransport $transport, string $property): mixed
    {
        return (new \ReflectionProperty($transport, $property))->getValue($transport);
    }
}

/** @internal */
final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|\Throwable> */
    private array $responses;

    /** @param list<ResponseInterface|\Throwable> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        if (!$response instanceof ResponseInterface) {
            throw new \LogicException('No HTTP response was queued for request '.$request->getMethod().'.');
        }

        return $response;
    }
}
