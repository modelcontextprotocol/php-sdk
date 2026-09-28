<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client;

use Mcp\Client\CancellationTokenInterface;
use Mcp\Client\Configuration;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\Exception\LogicException;
use Mcp\Exception\RequestCancelledException;
use Mcp\Exception\TimeoutException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Stateless\RequestMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class ProtocolTest extends TestCase
{
    #[TestDox('offers the configured protocol version in the handshake')]
    public function testOffersConfiguredVersion(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_06_18->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2025_06_18));

        $protocol->initialize($config);

        $this->assertSame(ProtocolVersion::V2025_06_18->value, $transport->offeredVersion);
    }

    #[TestDox('never sends "initialize" on a modern revision, which removed it')]
    public function testModernRevisionSkipsTheHandshake(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2026_07_28->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $protocol->initialize($config);

        $this->assertNotContains('initialize', $transport->methods);
        $this->assertNotContains('notifications/initialized', $transport->methods);
        $this->assertSame(ProtocolVersion::V2026_07_28, $protocol->getState()->getProtocolVersion());
        $this->assertTrue($protocol->getState()->isInitialized());
    }

    #[TestDox('carries the revision, capabilities and client info on every modern request')]
    public function testModernRequestsCarryTheEnvelope(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2026_07_28->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $protocol->initialize($config);

        $this->assertNotSame([], $transport->metas);

        foreach ($transport->metas as $meta) {
            $this->assertSame(ProtocolVersion::V2026_07_28->value, $meta[RequestMeta::PROTOCOL_VERSION] ?? null);
            $this->assertArrayHasKey(RequestMeta::CLIENT_CAPABILITIES, $meta);
            $this->assertSame('client-app', $meta[RequestMeta::CLIENT_INFO]['name'] ?? null);
        }
    }

    #[TestDox('a server that refuses "server/discover" still leaves a usable connection')]
    public function testDiscoveryFailureIsNotFatal(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2026_07_28->value, refuseDiscovery: true);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $protocol->initialize($config);

        $this->assertTrue($protocol->getState()->isInitialized());
    }

    #[TestDox('refuses to continue when discovery shows the server has no modern revision')]
    public function testDiscoveryWithoutAModernRevisionFails(): void
    {
        // Advertising only handshake revisions leaves nothing this connection
        // can use: it has already skipped the handshake.
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('does not support any modern protocol revision');

        $protocol->initialize($config);
    }

    #[TestDox('accepts a counter-offer the SDK can speak and records it as negotiated')]
    public function testAcceptsHandshakeCounterOffer(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2024_11_05->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2025_11_25));

        $result = $protocol->initialize($config);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(ProtocolVersion::V2024_11_05, $protocol->getState()->getProtocolVersion());
        $this->assertTrue($protocol->getState()->isInitialized());
    }

    #[TestDox('fails the handshake when the server answers with a version the SDK cannot speak')]
    #[DataProvider('provideUnusableCounterOffers')]
    public function testRejectsUnusableCounterOffer(string $counterOffer): void
    {
        $transport = new RecordingTransport($counterOffer);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2025_11_25));

        $result = $protocol->initialize($config);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString($counterOffer, $result->message);
        $this->assertNull($protocol->getState()->getProtocolVersion());
        $this->assertFalse($protocol->getState()->isInitialized());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnusableCounterOffers(): iterable
    {
        yield 'unknown revision' => ['2099-01-01'];
        // The modern era has no `initialize`, so a server answering the handshake
        // with one has produced a connection neither side can actually use.
        yield 'modern revision' => [ProtocolVersion::V2026_07_28->value];
    }

    #[TestDox('logs and ignores an id-less error response instead of crashing on it')]
    public function testIgnoresIdLessErrorResponse(): void
    {
        $protocol = new Protocol(logger: $logger = new CollectingLogger());

        $protocol->processMessage(json_encode([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'error' => ['code' => -32700, 'message' => 'Parse error'],
        ], \JSON_THROW_ON_ERROR));

        $this->assertCount(1, $logger->warnings);
    }

    #[TestDox('stores an error response under its id so the pending request can be correlated')]
    public function testErrorResponseWithIdIsStoredForItsPendingRequest(): void
    {
        $protocol = new Protocol();
        $protocol->getState()->addPendingRequest(7, 30);

        $protocol->processMessage('{"jsonrpc": "2.0", "id": 7, "error": {"code": -32601, "message": "Method not found"}}');

        $response = $protocol->getState()->consumeResponse(7);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(7, $response->getId());
        $this->assertSame(Error::METHOD_NOT_FOUND, $response->code);
    }

    #[TestDox('a response for a cancelled request cannot accumulate in state')]
    public function testIgnoresResponseForNoLongerPendingRequest(): void
    {
        $protocol = new Protocol();
        $protocol->getState()->addPendingRequest(7, 30);
        $protocol->getState()->removePendingRequest(7);

        $protocol->processMessage('{"jsonrpc": "2.0", "id": 7, "result": {}}');

        $this->assertNull($protocol->getState()->consumeResponse(7));
    }

    #[TestDox('reconnecting starts with a fresh tool catalog, not the previous server\'s verdicts')]
    public function testReconnectResetsToolCatalog(): void
    {
        $protocol = new Protocol();
        $protocol->connect(new RecordingTransport(ProtocolVersion::V2025_11_25->value), $config = $this->createConfiguration(ProtocolVersion::V2025_11_25));

        $protocol->getToolCatalog()->record([[
            'name' => 'broken',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['data' => ['type' => 'object', 'x-mcp-header' => 'Data']],
            ],
        ]]);

        $this->assertTrue($protocol->getToolCatalog()->isRejected('broken'));

        $protocol->connect(new RecordingTransport(ProtocolVersion::V2025_11_25->value), $config);

        $this->assertFalse($protocol->getToolCatalog()->isRejected('broken'), 'the previous server\'s verdict must not survive a reconnect');
    }

    #[TestDox('an empty inputResponses map is retried as a JSON object, never an array')]
    public function testEmptyInputResponsesEncodesAsJsonObject(): void
    {
        $transport = new InputRequiredRoundTripTransport();
        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $result = $protocol->request(new PingRequest(), 5);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertStringContainsString('"inputResponses":{}', $transport->retryBody);
        $this->assertStringNotContainsString('"inputResponses":[]', $transport->retryBody);
    }

    /** @return iterable<string, array{bool}> */
    public static function suspendedInterruptionProvider(): iterable
    {
        yield 'cancelled token' => [false];
        yield 'expired deadline' => [true];
    }

    #[DataProvider('suspendedInterruptionProvider')]
    public function testInterruptedSuspendedRequestRejectsBufferedResponse(bool $expireDeadline): void
    {
        $messages = [];
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(static function (string $data) use (&$messages): void {
            $messages[] = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        });
        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration(ProtocolVersion::V2025_11_25));
        $token = new TestCancellationToken();
        $fiber = new \Fiber(static fn () => $protocol->request(new CallToolRequest('slow', []), 5, false, $token, $expireDeadline ? 0.02 : null));
        $suspend = $fiber->start();
        $this->assertTrue($fiber->isSuspended());

        if ($expireDeadline) {
            // Wait for the actual suspended deadline, not an assumed timing window.
            for ($attempt = 0; $attempt < 1000 && microtime(true) < $suspend['deadline']; ++$attempt) {
                usleep(100);
            }
            $this->assertGreaterThanOrEqual($suspend['deadline'], microtime(true));
        } else {
            $token->cancelled = true;
        }

        $state = $protocol->getState();
        $requestId = $suspend['request_id'];
        $state->storeResponse($requestId, ['jsonrpc' => '2.0', 'id' => $requestId, 'result' => []]);
        // Match STDIO's response-first ordering: consume the reply, then resume.
        try {
            $fiber->resume($state->consumeResponse($requestId));
            $this->fail('An interrupted suspended request must reject its buffered reply.');
        } catch (RequestCancelledException|TimeoutException $e) {
            $this->assertInstanceOf($expireDeadline ? TimeoutException::class : RequestCancelledException::class, $e);
        }

        $this->assertSame([], $state->getPendingRequests());
        $this->assertNull($state->consumeResponse($requestId));
        $this->assertSame('notifications/cancelled', $messages[1]['method']);
        $this->assertSame($requestId, $messages[1]['params']['requestId']);

        $next = new \Fiber(static fn () => $protocol->request(new CallToolRequest('fast', []), 5));
        $nextSuspend = $next->start();
        $reply = new Response($nextSuspend['request_id'], ['content' => []]);
        $next->resume($reply);
        $this->assertSame($reply, $next->getReturn());
        $this->assertSame([], $state->getPendingRequests());
    }

    #[TestDox('a token that flips while the answer is on the wire cancels the request')]
    public function testCancellationDuringTheSendIsReported(): void
    {
        $token = new TestCancellationToken();
        $transport = new InterruptingTransport(static function (array $message) use ($token): void {
            if ('slow' === ($message['params']['name'] ?? null)) {
                $token->cancelled = true;
            }
        });

        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration(ProtocolVersion::V2025_11_25));

        try {
            $protocol->request(new CallToolRequest('slow', []), 5, false, $token);
            $this->fail('A cancelled request must not return the answer that arrived for it.');
        } catch (RequestCancelledException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
        }

        // The buffered answer and the pending entry go together: neither may
        // survive to confuse the next request.
        $this->assertNull($protocol->getState()->consumeResponse($transport->requestId('slow')));
        $this->assertSame([], $protocol->getState()->getPendingRequests());
        $this->assertSame('fast', $this->toolText($protocol->request(new CallToolRequest('fast', []), 5)));
    }

    #[TestDox('a deadline that passes while the answer is on the wire cancels the request')]
    public function testDeadlineDuringTheSendIsReported(): void
    {
        $transport = new InterruptingTransport(function (array $message): void {
            if ('slow' === ($message['params']['name'] ?? null)) {
                $this->waitPastDeadline();
            }
        });

        $protocol = new Protocol();
        $protocol->connect($transport, $this->createConfiguration(ProtocolVersion::V2025_11_25));

        try {
            $protocol->request(new CallToolRequest('slow', []), 5, false, null, self::DEADLINE_SECONDS);
            $this->fail('A request past its deadline must not return the answer that arrived for it.');
        } catch (TimeoutException $e) {
            $this->assertSame('The request deadline expired.', $e->getMessage());
        }

        $this->assertNull($protocol->getState()->consumeResponse($transport->requestId('slow')));
        $this->assertSame([], $protocol->getState()->getPendingRequests());
        $this->assertSame('fast', $this->toolText($protocol->request(new CallToolRequest('fast', []), 5)));
    }

    #[TestDox('a cancellation notification that cannot be sent is logged, and the interruption still propagates')]
    public function testNotificationFailureKeepsTheInterruption(): void
    {
        $token = new TestCancellationToken();
        $transport = new InterruptingTransport(static function (array $message) use ($token): void {
            if ('slow' === ($message['params']['name'] ?? null)) {
                $token->cancelled = true;
            }
        });
        $transport->failsNotifications = true;

        $protocol = new Protocol(logger: $logger = new CollectingLogger());
        $protocol->connect($transport, $this->createConfiguration(ProtocolVersion::V2025_11_25));

        try {
            $protocol->request(new CallToolRequest('slow', []), 5, false, $token);
            $this->fail('A cancelled request must not return the answer that arrived for it.');
        } catch (RequestCancelledException $e) {
            $this->assertSame('The client cancelled the request.', $e->getMessage());
        }

        $this->assertCount(1, $logger->warnings);
        $this->assertInstanceOf(ConnectionException::class, $logger->warnings[0]['exception'] ?? null);
        $this->assertSame('fast', $this->toolText($protocol->request(new CallToolRequest('fast', []), 5)));
    }

    /**
     * @param Response<array<string, mixed>>|Error $response
     */
    private function toolText(Response|Error $response): mixed
    {
        $this->assertInstanceOf(Response::class, $response);

        return CallToolResult::fromArray($response->result)->content[0]->text ?? null;
    }

    /**
     * A per-call timeout small enough that waiting it out costs microseconds.
     */
    private const DEADLINE_SECONDS = 0.001;

    /**
     * Wait until the per-call deadline has certainly passed. The deadline is
     * stamped as `microtime(true) + DEADLINE_SECONDS` before the send, so the
     * clock reaching twice that is a condition the test checks rather than
     * assumes. Bounded, so a clock that cannot advance fails instead of hanging.
     */
    private function waitPastDeadline(): void
    {
        $boundary = microtime(true) + 2 * self::DEADLINE_SECONDS;

        for ($attempt = 0; $attempt < 1000 && microtime(true) < $boundary; ++$attempt) {
            usleep(100);
        }

        $this->assertGreaterThanOrEqual($boundary, microtime(true), 'The clock must pass the per-call deadline for this assertion to be about the deadline.');
    }

    private function createConfiguration(ProtocolVersion $protocolVersion): Configuration
    {
        return new Configuration(
            clientInfo: new Implementation('client-app', '1.0.0'),
            capabilities: new ClientCapabilities(),
            protocolVersion: $protocolVersion,
        );
    }
}

/**
 * Answers the first request with an empty `input_required` ask and the retry
 * with success, capturing the retry's raw body so the test can inspect how
 * `inputResponses` was actually encoded on the wire.
 */
final class InputRequiredRoundTripTransport implements TransportInterface
{
    public string $retryBody = '';

    private int $calls = 0;
    private ClientStateInterface $state;

    public function setState(ClientStateInterface $state): void
    {
        $this->state = $state;
    }

    public function send(string $data): void
    {
        /** @var array{id: int} $message */
        $message = json_decode($data, true);
        $id = $message['id'];

        if (0 === $this->calls++) {
            $this->answer($id, ['resultType' => 'input_required', 'inputRequests' => []]);

            return;
        }

        $this->retryBody = $data;

        $this->answer($id, ['resultType' => 'complete']);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function answer(int $id, array $result): void
    {
        $this->state->storeResponse($id, [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function connect(): void
    {
    }

    public function close(): void
    {
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        throw new LogicException('Not used in this test.');
    }

    public function onInitialize(callable $callback): void
    {
    }

    public function onMessage(callable $callback): void
    {
    }

    public function onError(callable $callback): void
    {
    }

    public function onClose(callable $callback): void
    {
    }
}

/**
 * Transport that answers inline, so a request resolves without a Fiber
 * round-trip: `initialize` with a canned `protocolVersion`, and
 * `server/discover` with a minimal modern-era answer.
 */
final class RecordingTransport implements TransportInterface
{
    public ?string $offeredVersion = null;

    /** @var list<string> every method that reached the wire, in order */
    public array $methods = [];

    /** @var list<array<string, mixed>> the `_meta` each request carried */
    public array $metas = [];

    private ClientStateInterface $state;

    public function __construct(
        private readonly string $counterOffer,
        private readonly bool $refuseDiscovery = false,
    ) {
    }

    public function send(string $data): void
    {
        /** @var array{id?: int|string, method?: string, params?: array<string, mixed>} $message */
        $message = json_decode($data, true);
        $method = $message['method'] ?? null;

        if (!\is_string($method)) {
            return;
        }

        $this->methods[] = $method;
        $this->metas[] = $message['params']['_meta'] ?? [];

        if (!isset($message['id'])) {
            return;
        }

        if ('initialize' === $method) {
            $this->offeredVersion = $message['params']['protocolVersion'] ?? null;

            $this->answer($message['id'], [
                'protocolVersion' => $this->counterOffer,
                'capabilities' => [],
                'serverInfo' => ['name' => 'server', 'version' => '1.2.3'],
            ]);

            return;
        }

        if ('server/discover' !== $method) {
            return;
        }

        if ($this->refuseDiscovery) {
            $this->state->storeResponse($message['id'], [
                'jsonrpc' => MessageInterface::JSONRPC_VERSION,
                'id' => $message['id'],
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]);

            return;
        }

        $this->answer($message['id'], [
            'resultType' => 'complete',
            'supportedVersions' => [$this->counterOffer],
            'capabilities' => [],
            'serverInfo' => ['name' => 'server', 'version' => '1.2.3'],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function answer(int|string $id, array $result): void
    {
        $this->state->storeResponse($id, [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $id,
            'result' => $result,
        ]);
    }

    public function setState(ClientStateInterface $state): void
    {
        $this->state = $state;
    }

    public function connect(): void
    {
    }

    public function close(): void
    {
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        throw new LogicException('Not used in these tests.');
    }

    public function onInitialize(callable $callback): void
    {
    }

    public function onMessage(callable $callback): void
    {
    }

    public function onError(callable $callback): void
    {
    }

    public function onClose(callable $callback): void
    {
    }
}

/**
 * Logger that keeps the context of every warning, so a silent fallback can be
 * told apart from one the caller was told about.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array<string, mixed>> */
    public array $warnings = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            $this->warnings[] = $context;
        }
    }
}

/**
 * Token the tests flip from inside a transport, standing in for whatever asked
 * for a request to stop while it was already on the wire.
 */
final class TestCancellationToken implements CancellationTokenInterface
{
    public bool $cancelled = false;

    public function isCancellationRequested(): bool
    {
        return $this->cancelled;
    }
}

/**
 * Transport that runs a hook while a request is on the wire and then answers it
 * inline — the shape of a send whose reply is already buffered by the time the
 * caller can observe a token flip or a spent deadline.
 */
final class InterruptingTransport implements TransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /** Fail notification delivery, as a transport that has died would. */
    public bool $failsNotifications = false;

    /** @var \Closure(array<string, mixed>): void */
    private \Closure $duringRequest;

    private ClientStateInterface $state;

    /**
     * @param (\Closure(array<string, mixed>): void)|null $duringRequest
     */
    public function __construct(?\Closure $duringRequest = null)
    {
        $this->duringRequest = $duringRequest ?? static function (): void {
        };
    }

    public function setState(ClientStateInterface $state): void
    {
        $this->state = $state;
    }

    public function send(string $data): void
    {
        /** @var array<string, mixed> $message */
        $message = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        $this->messages[] = $message;

        if (!\array_key_exists('id', $message)) {
            if ($this->failsNotifications) {
                throw new ConnectionException('The transport is gone.');
            }

            return;
        }

        ($this->duringRequest)($message);

        $this->state->storeResponse($message['id'], [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $message['id'],
            'result' => ['content' => [['type' => 'text', 'text' => $message['params']['name'] ?? '']]],
        ]);
    }

    /**
     * The request id recorded for this tool name.
     */
    public function requestId(string $name): int|string
    {
        foreach ($this->messages as $message) {
            if ($name === ($message['params']['name'] ?? null)) {
                $id = $message['id'] ?? null;

                if (\is_int($id) || \is_string($id)) {
                    return $id;
                }
            }
        }

        throw new \RuntimeException(\sprintf('No request recorded for tool "%s".', $name));
    }

    public function connect(): void
    {
    }

    public function close(): void
    {
    }

    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        throw new LogicException('Not used in these tests.');
    }

    public function onInitialize(callable $callback): void
    {
    }

    public function onMessage(callable $callback): void
    {
    }

    public function onError(callable $callback): void
    {
    }

    public function onClose(callable $callback): void
    {
    }
}
