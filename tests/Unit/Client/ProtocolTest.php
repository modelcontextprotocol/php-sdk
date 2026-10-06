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

use Mcp\Client\Configuration;
use Mcp\Client\Protocol;
use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\LogicException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
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

    #[TestDox('a server that refuses "server/discover" is reached through the handshake instead')]
    public function testRefusedProbeFallsBackToTheHandshake(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value, refuseDiscovery: true);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $this->assertInstanceOf(Response::class, $protocol->initialize($config));

        $this->assertSame(['server/discover', 'initialize', 'notifications/initialized'], $transport->methods);
        $this->assertSame(ProtocolVersion::V2025_11_25->value, $transport->offeredVersion);
        $this->assertSame(ProtocolVersion::V2025_11_25, $protocol->getState()->getProtocolVersion());
        $this->assertTrue($protocol->getState()->isInitialized());

        // Nothing after the fallback carries the modern envelope.
        $this->assertArrayNotHasKey(RequestMeta::PROTOCOL_VERSION, $transport->metas[1]);
    }

    #[TestDox('the fallback offers the configured handshake revision')]
    public function testFallbackOffersTheConfiguredRevision(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_06_18->value, refuseDiscovery: true);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_06_18));

        $protocol->initialize($config);

        $this->assertSame(ProtocolVersion::V2025_06_18->value, $transport->offeredVersion);
        $this->assertSame(ProtocolVersion::V2025_06_18, $protocol->getState()->getProtocolVersion());
    }

    #[TestDox('a server that never answers the probe is reached through the handshake once it times out')]
    public function testSilentProbeFallsBackToTheHandshake(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value, ignoreDiscovery: true);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        // The transport times a request out by resuming its fiber with an error;
        // driven by hand here, the way StdioTransport::tick() would.
        $fiber = new \Fiber(static fn () => $protocol->initialize($config));
        $suspended = $fiber->start();

        $this->assertSame('await_response', $suspended['type']);
        $fiber->resume(Error::forInternalError('Request timed out', $suspended['request_id']));

        $this->assertTrue($fiber->isTerminated());
        $this->assertInstanceOf(Response::class, $fiber->getReturn());
        $this->assertSame(ProtocolVersion::V2025_11_25, $protocol->getState()->getProtocolVersion());
    }

    #[TestDox('a refusal naming only handshake revisions falls back rather than failing')]
    public function testRefusalNamingHandshakeRevisionsFallsBack(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value, discoveryError: Error::forUnsupportedProtocolVersion('2026-07-28', ProtocolVersion::handshakeVersions()));
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $this->assertInstanceOf(Response::class, $protocol->initialize($config));
        $this->assertSame(ProtocolVersion::V2025_11_25, $protocol->getState()->getProtocolVersion());
    }

    #[TestDox('a refusal naming no revision this client speaks fails without a handshake')]
    public function testRefusalNamingNothingUsableFails(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value, discoveryError: new Error(1, Error::UNSUPPORTED_PROTOCOL_VERSION, 'Unsupported protocol version', ['requested' => '2026-07-28', 'supported' => ['2099-01-01']]));
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $result = $protocol->initialize($config);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('2099-01-01', $result->message);
        $this->assertNotContains('initialize', $transport->methods);
        $this->assertFalse($protocol->getState()->isInitialized());
    }

    #[TestDox('a server advertising only handshake revisions is reached through the handshake')]
    public function testDiscoveryWithoutAModernRevisionFallsBack(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $this->assertInstanceOf(Response::class, $protocol->initialize($config));
        $this->assertSame(['server/discover', 'initialize', 'notifications/initialized'], $transport->methods);
        $this->assertSame(ProtocolVersion::V2025_11_25, $protocol->getState()->getProtocolVersion());
    }

    #[TestDox('a modern-only client fails against a server without the modern era')]
    public function testModernOnlyClientDoesNotFallBack(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::V2025_11_25->value, refuseDiscovery: true);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28, null));

        $result = $protocol->initialize($config);

        $this->assertInstanceOf(Error::class, $result);
        $this->assertStringContainsString('without a handshake fallback', $result->message);
        $this->assertSame(['server/discover'], $transport->methods);
        $this->assertFalse($protocol->getState()->isInitialized());
    }

    #[TestDox('a handshake refused because the server already settled on the modern era probes again')]
    public function testLateModernSettlementIsProbedAgain(): void
    {
        // The first probe went unanswered in time; by the handshake, the server
        // had answered it and settled on the modern era.
        $transport = new RecordingTransport(
            ProtocolVersion::V2025_11_25->value,
            refuseDiscovery: true,
            initializeError: Error::forUnsupportedProtocolVersion('2025-11-25', [ProtocolVersion::V2026_07_28]),
            discoverAfterInitialize: true,
        );
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $this->assertInstanceOf(Response::class, $protocol->initialize($config));
        $this->assertSame(['server/discover', 'initialize', 'server/discover'], $transport->methods);
        $this->assertSame(ProtocolVersion::V2026_07_28, $protocol->getState()->getProtocolVersion());
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

        $protocol->processMessage('{"jsonrpc": "2.0", "id": 7, "error": {"code": -32601, "message": "Method not found"}}');

        $response = $protocol->getState()->consumeResponse(7);

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(7, $response->getId());
        $this->assertSame(Error::METHOD_NOT_FOUND, $response->code);
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
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));
        $protocol->initialize($config);

        $result = $protocol->request(new PingRequest(), 5);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertStringContainsString('"inputResponses":{}', $transport->retryBody);
        $this->assertStringNotContainsString('"inputResponses":[]', $transport->retryBody);
    }

    private function createConfiguration(ProtocolVersion $protocolVersion, ?ProtocolVersion $fallback = ProtocolVersion::V2025_11_25): Configuration
    {
        return new Configuration(
            clientInfo: new Implementation('client-app', '1.0.0'),
            capabilities: new ClientCapabilities(),
            protocolVersion: $protocolVersion,
            fallbackProtocolVersion: $fallback,
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
        /** @var array{id: int, method: string} $message */
        $message = json_decode($data, true);
        $id = $message['id'];

        if ('server/discover' === $message['method']) {
            $this->answer($id, ['resultType' => 'complete', 'supportedVersions' => [ProtocolVersion::V2026_07_28->value], 'capabilities' => []]);

            return;
        }

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

    private bool $initialized = false;

    public function __construct(
        private readonly string $counterOffer,
        private readonly bool $refuseDiscovery = false,
        private readonly bool $ignoreDiscovery = false,
        private readonly ?Error $discoveryError = null,
        private readonly ?Error $initializeError = null,
        private readonly bool $discoverAfterInitialize = false,
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
            $this->initialized = true;

            if (null !== $this->initializeError) {
                $this->fail($message['id'], $this->initializeError);

                return;
            }

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

        if ($this->ignoreDiscovery) {
            return;
        }

        $discoverable = $this->discoverAfterInitialize && $this->initialized;

        if (null !== $this->discoveryError && !$discoverable) {
            $this->fail($message['id'], $this->discoveryError);

            return;
        }

        if ($this->refuseDiscovery && !$discoverable) {
            $this->fail($message['id'], Error::forMethodNotFound('Method not found'));

            return;
        }

        $this->answer($message['id'], [
            'resultType' => 'complete',
            'supportedVersions' => [$discoverable ? ProtocolVersion::V2026_07_28->value : $this->counterOffer],
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

    private function fail(int|string $id, Error $error): void
    {
        $this->state->storeResponse($id, (new Error($id, $error->code, $error->message, $error->data))->jsonSerialize());
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
