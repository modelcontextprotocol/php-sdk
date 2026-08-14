<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Request;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Configuration;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class InitializeHandlerTest extends TestCase
{
    #[TestDox('uses configuration protocol version when provided')]
    public function testHandleUsesConfigurationProtocolVersion(): void
    {
        $customProtocolVersion = ProtocolVersion::V2024_11_05;

        $configuration = new Configuration(
            serverInfo: new Implementation('server', '1.2.3'),
            capabilities: new ServerCapabilities(),
            protocolVersion: $customProtocolVersion,
        );

        $handler = new InitializeHandler($configuration);

        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->exactly(3))
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value): void {
                match ($key) {
                    'client_info' => $this->assertSame(['name' => 'client-app', 'version' => '1.0.0'], $value),
                    'client_capabilities' => $this->assertEquals(new \stdClass(), $value),
                    'protocol_version' => $this->assertSame(ProtocolVersion::V2024_11_05->value, $value),
                    default => $this->fail("Unexpected session key: {$key}"),
                };
            });

        $request = $this->createInitializeRequest(ProtocolVersion::V2024_11_05->value);

        $response = $handler->handle($request, $session);

        $this->assertInstanceOf(InitializeResult::class, $response->result);

        /** @var InitializeResult $result */
        $result = $response->result;

        $this->assertSame($customProtocolVersion, $result->protocolVersion);
        $this->assertSame(
            $customProtocolVersion->value,
            $result->jsonSerialize()['protocolVersion']
        );
    }

    #[TestDox('answers an unpinned handshake per the negotiation table')]
    #[DataProvider('provideNegotiationTable')]
    public function testNegotiationTable(string $requested, ProtocolVersion $expected): void
    {
        $handler = new InitializeHandler($this->createConfiguration());

        $response = $handler->handle(
            $this->createInitializeRequest($requested),
            $this->createStub(SessionInterface::class),
        );

        \assert($response->result instanceof InitializeResult);
        $this->assertNotNull($response->result->protocolVersion);
        $this->assertSame($expected, $response->result->protocolVersion);

        // No row may resolve to a modern revision, whatever the client sent — that
        // era has no `initialize`, so the answer would be unusable for both sides.
        $this->assertFalse($response->result->protocolVersion->isModern());
    }

    /**
     * The negotiation table from docs/server-builder.md, one data set per case a
     * client can present. Keeping the rows in a single provider is what stops the
     * documented table and the tested behaviour from drifting apart.
     *
     * @return iterable<string, array{string, ProtocolVersion}>
     */
    public static function provideNegotiationTable(): iterable
    {
        $counterOffer = ProtocolVersion::latestHandshake();

        // Driven off the enum rather than a literal list, so a new revision is
        // covered by the era it is declared in the moment it lands.
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield \sprintf('supported %s -> echoed back', $version->value) => [$version->value, $version];
        }

        yield 'unknown future revision -> counter-offer' => ['2099-01-01', $counterOffer];
        yield 'not a revision at all -> counter-offer' => ['banana', $counterOffer];
        yield 'empty -> counter-offer' => ['', $counterOffer];

        foreach (ProtocolVersion::modernVersions() as $version) {
            yield \sprintf('modern %s -> counter-offer', $version->value) => [$version->value, $counterOffer];
        }
    }

    #[TestDox('a modern version pinned in configuration cannot leak into the handshake')]
    public function testModernConfiguredVersionFallsBackToHandshakeSet(): void
    {
        $handler = new InitializeHandler($this->createConfiguration(ProtocolVersion::V2026_07_28));

        $response = $handler->handle(
            $this->createInitializeRequest(ProtocolVersion::V2025_06_18->value),
            $this->createStub(SessionInterface::class),
        );

        \assert($response->result instanceof InitializeResult);
        $this->assertSame(ProtocolVersion::V2025_06_18, $response->result->protocolVersion);
    }

    #[TestDox('a pinned version wins over a different version requested by the client')]
    public function testPinnedVersionOverridesClientRequest(): void
    {
        $handler = new InitializeHandler($this->createConfiguration(ProtocolVersion::V2025_03_26));

        $response = $handler->handle(
            $this->createInitializeRequest(ProtocolVersion::V2025_11_25->value),
            $this->createStub(SessionInterface::class),
        );

        \assert($response->result instanceof InitializeResult);
        $this->assertSame(ProtocolVersion::V2025_03_26, $response->result->protocolVersion);
    }

    #[TestDox('stores the negotiated version on the session')]
    public function testStoresNegotiatedVersionOnSession(): void
    {
        $handler = new InitializeHandler($this->createConfiguration());

        $stored = [];
        $session = $this->createMock(SessionInterface::class);
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$stored): void {
            $stored[$key] = $value;
        });

        $handler->handle($this->createInitializeRequest(ProtocolVersion::V2025_06_18->value), $session);

        $this->assertSame(ProtocolVersion::V2025_06_18->value, $stored['protocol_version'] ?? null);
    }

    #[TestDox('falls back to empty defaults when constructed without a configuration')]
    public function testHandlesMissingConfiguration(): void
    {
        // The reads in handle() sit on the left of ??, which has isset semantics and
        // therefore tolerates the null without a nullsafe operator. This test is what
        // proves that, rather than the shape of the accessor.
        $handler = new InitializeHandler();

        $response = $handler->handle(
            $this->createInitializeRequest(ProtocolVersion::V2025_06_18->value),
            $this->createStub(SessionInterface::class),
        );

        \assert($response->result instanceof InitializeResult);
        $this->assertEquals(new ServerCapabilities(), $response->result->capabilities);
        $this->assertEquals(new Implementation(), $response->result->serverInfo);
        $this->assertNull($response->result->instructions);
        $this->assertSame(ProtocolVersion::V2025_06_18, $response->result->protocolVersion);
    }

    private function createConfiguration(?ProtocolVersion $protocolVersion = null): Configuration
    {
        return new Configuration(
            serverInfo: new Implementation('server', '1.2.3'),
            capabilities: new ServerCapabilities(),
            protocolVersion: $protocolVersion,
        );
    }

    private function createInitializeRequest(string $protocolVersion): InitializeRequest
    {
        return InitializeRequest::fromArray([
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => 'request-1',
            'method' => InitializeRequest::getMethod(),
            'params' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'client-app',
                    'version' => '1.0.0',
                ],
            ],
        ]);
    }
}
