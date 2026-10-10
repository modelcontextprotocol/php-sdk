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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionLockInterface;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\PassthroughMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Tests\Unit\Server\Session\Fixture\InterleavingSessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class StreamableHttpTransportTest extends TestCase
{
    private Psr17Factory $factory;

    private ?string $lockDirectory = null;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
    }

    protected function tearDown(): void
    {
        if (null === $this->lockDirectory || !is_dir($this->lockDirectory)) {
            return;
        }

        foreach (glob($this->lockDirectory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->lockDirectory);
    }

    #[TestDox('default middleware is applied when none is passed')]
    public function testDefaultMiddlewareIsAppliedWhenOmitted(): void
    {
        // Preflight: OPTIONS + Access-Control-Request-Method — CorsMiddleware advertises Methods/Headers only on preflight.
        $request = $this->factory
            ->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Access-Control-Request-Method', 'POST');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin')); // secure-by-default
        $this->assertSame('GET, POST, DELETE', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertNotSame('', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertNotSame('', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('default middleware blocks non-localhost Origin')]
    public function testDefaultMiddlewareBlocksRebindingAttempt(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'http://evil.example.com');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(403, $response->getStatusCode());
    }

    #[TestDox('default middleware rejects unsupported MCP-Protocol-Version')]
    public function testDefaultMiddlewareRejectsUnsupportedProtocolVersion(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, '1900-01-01');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('malformed MCP session IDs are rejected as bad requests')]
    public function testMalformedSessionIdHeaderReturnsBadRequest(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader(StreamableHttpTransport::SESSION_HEADER, '{"not":"a-token"}');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString(StreamableHttpTransport::SESSION_HEADER, (string) $response->getBody());
    }

    #[TestDox('duplicate MCP session ID headers are rejected as bad requests')]
    public function testDuplicateSessionIdHeadersReturnBadRequest(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader(StreamableHttpTransport::SESSION_HEADER, '2fb587fc-593f-47ce-9d9a-9c06f2b907a3')
            ->withAddedHeader(StreamableHttpTransport::SESSION_HEADER, '5e583da8-a677-4446-b723-4ddbe00fda62');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $response = $transport->listen();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('must not be repeated', (string) $response->getBody());
    }

    #[TestDox('pass-through middleware disables defaults without a warning log')]
    public function testPassthroughMiddlewareDisablesDefaultsWithoutWarning(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'evil.example.com')
            ->withHeader('Origin', 'http://evil.example.com');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            $logger,
            [new PassthroughMiddleware()],
        );

        $response = $transport->listen();

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[TestDox('explicit empty middleware list disables defaults and emits a warning log')]
    public function testEmptyMiddlewareListDisablesDefaultsAndWarns(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'evil.example.com')
            ->withHeader('Origin', 'http://evil.example.com');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('empty middleware list'));

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            $logger,
            [],
        );

        $response = $transport->listen();

        // No CORS, no DNS rebinding check — transport just answers.
        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertFalse($response->hasHeader('Access-Control-Allow-Methods'));
    }

    #[TestDox('a custom middleware list carrying ProtocolVersionMiddleware warns that it will reject the modern era')]
    public function testCustomMiddlewareWithProtocolVersionMiddlewareWarns(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('ProtocolVersionMiddleware'));

        new StreamableHttpTransport(
            $this->factory->createServerRequest('POST', 'http://localhost/')->withHeader('Host', 'localhost'),
            $this->factory,
            $this->factory,
            $logger,
            [new CorsMiddleware(), new DnsRebindingProtectionMiddleware(), new ProtocolVersionMiddleware()],
        );
    }

    #[TestDox('null middleware does not trigger the empty-list warning')]
    public function testNullMiddlewareDoesNotWarn(): void
    {
        $request = $this->factory
            ->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory, $logger);
        $transport->listen();
    }

    #[TestDox('custom middleware composes with default stack via spread')]
    public function testDefaultsCanBeSpreadAndExtended(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                ...StreamableHttpTransport::defaultMiddleware(),
                $this->stubAuth401(),
            ],
        );

        $response = $transport->listen();

        $this->assertSame(401, $response->getStatusCode());
        // CORS middleware is outermost — Expose-Headers is emitted on all responses, including 401.
        $this->assertSame('Mcp-Session-Id, WWW-Authenticate', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('defaults can be filtered to drop DNS rebinding for proxy deployments')]
    public function testDefaultsCanBeFilteredToDropDnsRebinding(): void
    {
        // Behind a reverse proxy: real Host is api.myapp.com, browser Origin is myapp.com.
        // DnsRebindingProtectionMiddleware default (localhost-only) would 403 this — drop it.
        $request = $this->factory
            ->createServerRequest('POST', 'http://api.myapp.com/')
            ->withHeader('Host', 'api.myapp.com')
            ->withHeader('Origin', 'https://myapp.com');

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                ...array_filter(
                    StreamableHttpTransport::defaultMiddleware(),
                    static fn (MiddlewareInterface $m): bool => !$m instanceof DnsRebindingProtectionMiddleware,
                ),
                $this->stubAuth401(),
            ],
        );

        $response = $transport->listen();

        // Auth short-circuits with 401 — proves DNS rebinding didn't reject the request first.
        $this->assertSame(401, $response->getStatusCode());
        // CORS middleware is still in the chain — Expose-Headers attached to the 401.
        $this->assertSame('Mcp-Session-Id, WWW-Authenticate', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    #[TestDox('configured CorsMiddleware reflects matching Origin')]
    public function testConfiguredCorsReflectsMatchingOrigin(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Origin', 'https://myapp.example.com');

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [
                new CorsMiddleware(allowedOrigins: ['https://myapp.example.com']),
                new DnsRebindingProtectionMiddleware(allowedHosts: ['localhost']),
                new ProtocolVersionMiddleware(),
            ],
        );

        $response = $transport->listen();

        $this->assertSame('https://myapp.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[TestDox('middleware runs before transport handles the request')]
    public function testMiddlewareRunsBeforeTransportHandlesRequest(): void
    {
        $request = $this->factory->createServerRequest('OPTIONS', 'http://localhost/')
            ->withHeader('Host', 'localhost');

        $state = new \stdClass();
        $state->called = false;
        $spy = new class($state) implements MiddlewareInterface {
            public function __construct(private \stdClass $state)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->state->called = true;

                return $handler->handle($request);
            }
        };

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [$spy],
        );

        $response = $transport->listen();

        $this->assertTrue($state->called);
        $this->assertSame(204, $response->getStatusCode());
    }

    #[TestDox('non-middleware entries are rejected')]
    public function testInvalidMiddlewareEntryThrows(): void
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $this->expectException(InvalidArgumentException::class);

        new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            null,
            [new \stdClass()], // @phpstan-ignore-line argument.type
        );
    }

    public function testPostBodyExceedingMaxBytesReturns413(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withBody($this->factory->createStream(str_repeat('a', 64)));

        // Empty middleware bypasses the default security stack to isolate body-size handling.
        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 16);

        $response = $transport->listen();

        $this->assertSame(413, $response->getStatusCode());
    }

    public function testPostBodyWithinMaxBytesIsNotRejected(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withBody($this->factory->createStream('{}'));

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 1024);

        $response = $transport->listen();

        $this->assertNotSame(413, $response->getStatusCode());
    }

    public function testNonPositiveMaxBodyBytesThrows(): void
    {
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $this->expectException(InvalidArgumentException::class);

        new StreamableHttpTransport($request, $this->factory, $this->factory, null, [], maxBodyBytes: 0);
    }

    #[TestDox('an immediate response is consumed once and not replayed on a later POST')]
    public function testImmediateResponseIsNotReplayedOnSecondPost(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withBody($this->factory->createStream('{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        $transport = new StreamableHttpTransport($request, $this->factory, $this->factory);

        $calls = 0;
        $transport->onMessage(static function (TransportInterface $transport, string $payload) use (&$calls): void {
            if (1 === ++$calls) {
                $transport->send('{"jsonrpc":"2.0","id":1,"result":{}}', ['status_code' => 200]);
            }
        });

        $first = $transport->listen();

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', (string) $first->getBody());

        $second = $transport->listen();

        $this->assertSame(2, $calls);
        $this->assertSame(202, $second->getStatusCode());
        $this->assertSame('', (string) $second->getBody());
    }

    #[TestDox('the polling loop times out a pending request via the injected clock')]
    public function testPollingLoopTimesOutPendingRequestViaInjectedClock(): void
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withBody($this->factory->createStream('{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        $requestedAt = 1_000_000;

        // Frozen 121s after the pending request was issued — past its 120s timeout.
        $clock = new class($requestedAt + 121) implements ClockInterface {
            public function __construct(private readonly int $timestamp)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable())->setTimestamp($this->timestamp);
            }
        };

        $transport = new StreamableHttpTransport(
            $request,
            $this->factory,
            $this->factory,
            clock: $clock,
        );

        $received = null;
        $fiber = new \Fiber(static function () use (&$received) {
            $received = \Fiber::suspend();

            return null;
        });
        $fiber->start();

        $transport->onMessage(static function (TransportInterface $transport) use ($fiber): void {
            $transport->attachFiberToSession($fiber, Uuid::v4());
        });
        $transport->setOutgoingMessagesProvider(static fn (): array => []);
        $transport->setResponseFinder(static fn () => null);
        $transport->setPendingRequestsProvider(static fn (): array => [
            ['request_id' => 1, 'timestamp' => $requestedAt, 'timeout' => 120],
        ]);

        $response = $transport->listen();

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        $this->expectOutputString('');
        $response->getBody()->getContents();

        $this->assertTrue($fiber->isTerminated());
        $this->assertInstanceOf(Error::class, $received);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideInterleavings(): iterable
    {
        yield 'B runs between A saving its session and A answering' => [false];
        yield 'B loaded the session before A saved it (lost update)' => [true];
    }

    #[TestDox('concurrent POSTs of one session each get their own response: $_dataName')]
    #[DataProvider('provideInterleavings')]
    public function testConcurrentPostsOfOneSessionEachGetTheirOwnResponse(bool $readBeforeWrite): void
    {
        $store = new InterleavingSessionStore();
        $sessionId = $this->post($store, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}')
            ->getHeaderLine(StreamableHttpTransport::SESSION_HEADER);

        $responseB = null;
        $store->interleaveOnNextWrite(function () use ($store, $sessionId, &$responseB): void {
            $responseB = $this->post($store, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"b"}}}', $sessionId);
        }, $readBeforeWrite);

        $responseA = $this->post($store, '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"a"}}}', $sessionId);

        $this->assertInstanceOf(ResponseInterface::class, $responseB);
        foreach ([2 => $responseA, 3 => $responseB] as $id => $response) {
            $this->assertSame(200, $response->getStatusCode(), \sprintf('Request %d was answered %d.', $id, $response->getStatusCode()));
            $this->assertSame($sessionId, $response->getHeaderLine(StreamableHttpTransport::SESSION_HEADER));
            $this->assertSame($id, json_decode((string) $response->getBody(), true)['id'] ?? null, \sprintf('Request %d got: %s', $id, $response->getBody()));
        }
    }

    #[TestDox('a batch streamed over SSE still carries the responses that did not suspend')]
    public function testStreamedBatchCarriesItsOtherResponses(): void
    {
        $store = new InterleavingSessionStore();
        $sessionId = $this->post($store, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}')
            ->getHeaderLine(StreamableHttpTransport::SESSION_HEADER);

        $response = $this->post($store, '[{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"progress","arguments":{},"_meta":{"progressToken":"p"}}},{"jsonrpc":"2.0","id":3,"method":"ping"}]', $sessionId);

        $this->assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        // The stream calls ob_flush() itself, so the output is captured by a handler, not a plain buffer.
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

        $this->assertMatchesRegularExpression('/"id":3,"result".*"progressToken":"p".*"id":2,"result"/s', $output);
    }

    #[TestDox('a batch answered as JSON carries the queued notifications first, then its responses, in one array')]
    public function testJsonBatchCarriesQueuedNotificationsAndItsResponses(): void
    {
        $store = new InterleavingSessionStore();
        $sessionId = $this->post($store, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}')
            ->getHeaderLine(StreamableHttpTransport::SESSION_HEADER);

        // A notification another request of the session queued, e.g. a resource update.
        $session = new Session($store, Uuid::fromString($sessionId));
        $session->set('_mcp.outgoing_queue', [[
            'message' => '{"jsonrpc":"2.0","method":"notifications/resources/updated","params":{"uri":"file:///a"}}',
            'context' => ['type' => 'notification'],
        ]]);
        $session->save();

        $response = $this->post($store, '[{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"a"}}},{"jsonrpc":"2.0","id":3,"method":"ping"}]', $sessionId);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame($sessionId, $response->getHeaderLine(StreamableHttpTransport::SESSION_HEADER));

        $messages = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($messages);
        $this->assertTrue(array_is_list($messages));
        $this->assertSame(
            ['notifications/resources/updated', 2, 3],
            array_map(static fn (array $message): string|int => $message['method'] ?? $message['id'], $messages),
        );
    }

    #[TestDox('with a session lock, a POST arriving while another of the session runs is held off instead of overwriting it')]
    public function testSessionLockHoldsOffAConcurrentPostOfTheSameSession(): void
    {
        $store = new InterleavingSessionStore();
        $sessionId = $this->post($store, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}', lock: $this->fileLock())
            ->getHeaderLine(StreamableHttpTransport::SESSION_HEADER);

        // B loads the session before A saved it, the lost update: on its own worker, with its own lock instance.
        $responseB = null;
        $store->interleaveOnNextWrite(function () use ($store, $sessionId, &$responseB): void {
            $responseB = $this->post($store, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"b"}}}', $sessionId, $this->fileLock());
        }, readBeforeWrite: true);

        $responseA = $this->post($store, '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo","arguments":{"text":"a"}}}', $sessionId, $this->fileLock());

        $this->assertSame(200, $responseA->getStatusCode());
        $this->assertSame(2, json_decode((string) $responseA->getBody(), true)['id'] ?? null);

        $this->assertInstanceOf(ResponseInterface::class, $responseB);
        $this->assertSame(503, $responseB->getStatusCode());
        $error = json_decode((string) $responseB->getBody(), true);
        $this->assertSame(3, $error['id'] ?? null);
        $this->assertSame(Error::SERVER_ERROR, $error['error']['code'] ?? null);
    }

    #[TestDox('the session lock is released while a handler waits for the client, so the next request of the session proceeds')]
    public function testSessionLockIsReleasedWhileAHandlerWaitsForTheClient(): void
    {
        $store = new InterleavingSessionStore();
        $lock = $this->fileLock();
        $sessionId = $this->post($store, '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}', lock: $lock)
            ->getHeaderLine(StreamableHttpTransport::SESSION_HEADER);

        $first = $this->post($store, '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"ask","arguments":{}}}', $sessionId, $lock);
        $second = $this->post($store, '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"ask","arguments":{}}}', $sessionId, $lock);

        $this->assertSame('text/event-stream', $first->getHeaderLine('Content-Type'));
        $this->assertSame('text/event-stream', $second->getHeaderLine('Content-Type'));

        // Both requests to the client were stored, each under its own id.
        $session = new Session($store, Uuid::fromString($sessionId));
        $this->assertSame([1000, 1001], array_keys($session->get('_mcp.pending_requests')));
    }

    /**
     * Sends one POST to a fresh server sharing $store, like a PHP worker would.
     */
    private function post(InterleavingSessionStore $store, string $body, string $sessionId = '', ?SessionLockInterface $lock = null): ResponseInterface
    {
        $request = $this->factory
            ->createServerRequest('POST', 'http://localhost/')
            ->withHeader('Host', 'localhost')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/event-stream')
            ->withBody($this->factory->createStream($body));

        if ('' !== $sessionId) {
            $request = $request
                ->withHeader(StreamableHttpTransport::SESSION_HEADER, $sessionId)
                ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, '2025-06-18');
        }

        $builder = Server::builder()
            ->setServerInfo('test', '1.0')
            ->setSession($store)
            ->addTool(static fn (string $text): string => $text, 'echo')
            ->addTool(static function (RequestContext $context): string {
                $context->getClientGateway()->progress(0.5);

                return 'done';
            }, 'progress')
            ->addTool(static function (RequestContext $context): string {
                // Suspends on a request to the client, as elicitation and sampling do.
                \Fiber::suspend(new RequestSuspension(new PingRequest(), $context->getSession()->getId()->toRfc4122(), 5));

                return 'done';
            }, 'ask');

        if (null !== $lock) {
            $builder->setSessionLock($lock);
        }

        return $builder->build()->run(new StreamableHttpTransport($request, $this->factory, $this->factory));
    }

    /**
     * A lock instance of its own, as each worker has, over one shared directory.
     */
    private function fileLock(): FileSessionLock
    {
        $this->lockDirectory ??= sys_get_temp_dir().'/mcp-session-lock-'.bin2hex(random_bytes(6));

        return new FileSessionLock($this->lockDirectory, timeout: 0.05);
    }

    private function stubAuth401(): MiddlewareInterface
    {
        return new class($this->factory) implements MiddlewareInterface {
            public function __construct(private ResponseFactoryInterface $factory)
            {
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $this->factory->createResponse(401);
            }
        };
    }
}
