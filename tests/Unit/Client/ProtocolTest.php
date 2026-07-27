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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

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

    #[TestDox('never offers a modern version over the initialize handshake')]
    public function testDoesNotOfferModernVersionOverHandshake(): void
    {
        $transport = new RecordingTransport(ProtocolVersion::latestHandshake()->value);
        $protocol = new Protocol();
        $protocol->connect($transport, $config = $this->createConfiguration(ProtocolVersion::V2026_07_28));

        $protocol->initialize($config);

        $this->assertSame(ProtocolVersion::latestHandshake()->value, $transport->offeredVersion);
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
 * Transport that answers the `initialize` request inline with a canned
 * `protocolVersion`, so the handshake resolves without a Fiber round-trip.
 */
final class RecordingTransport implements TransportInterface
{
    public ?string $offeredVersion = null;

    private ClientStateInterface $state;

    public function __construct(private readonly string $counterOffer)
    {
    }

    public function send(string $data): void
    {
        /** @var array{id: int|string, method: string, params?: array{protocolVersion?: string}} $message */
        $message = json_decode($data, true);

        if ('initialize' !== ($message['method'] ?? null)) {
            return;
        }

        $this->offeredVersion = $message['params']['protocolVersion'] ?? null;

        $this->state->storeResponse($message['id'], [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $message['id'],
            'result' => [
                'protocolVersion' => $this->counterOffer,
                'capabilities' => [],
                'serverInfo' => ['name' => 'server', 'version' => '1.2.3'],
            ],
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
