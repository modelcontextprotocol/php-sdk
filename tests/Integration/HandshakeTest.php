<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Protocol version negotiation, run across both implementations at once.
 *
 * The unit tests either drive the client against a canned counter-offer or the
 * server against a canned request. Neither can show that the version this
 * server answers with is the version this client ends up on.
 */
final class HandshakeTest extends IntegrationTestCase
{
    #[TestDox('client and server agree on a revision')]
    #[DataProvider('provideNegotiations')]
    public function testNegotiatedVersion(?ProtocolVersion $clientVersion, ?ProtocolVersion $serverVersion, ProtocolVersion $expected): void
    {
        $server = $this->serverBuilder();
        if (null !== $serverVersion) {
            $server->setProtocolVersion($serverVersion);
        }

        $client = $this->clientBuilder();
        if (null !== $clientVersion) {
            $client->setProtocolVersion($clientVersion);
        }

        $connected = $this->connect($server, $client);

        $this->assertSame($expected, $connected->getProtocolVersion());
    }

    /**
     * @return iterable<string, array{?ProtocolVersion, ?ProtocolVersion, ProtocolVersion}>
     */
    public static function provideNegotiations(): iterable
    {
        $latest = ProtocolVersion::latestHandshake();

        yield 'both unconfigured' => [null, null, $latest];

        // A client asking for a revision the server supports gets that exact one
        // back, whichever end of the supported range it sits at.
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield \sprintf('client asks for %s', $version->value) => [$version, null, $version];
        }

        // A pinned server answers with its pin, and this client continues on it
        // rather than insisting on what it asked for.
        yield 'server pins an older revision' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_03_26, ProtocolVersion::V2025_03_26];
        yield 'server pins a newer revision' => [ProtocolVersion::V2024_11_05, ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25];
        yield 'both pin the same revision' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18];

        // Neither side can reach the modern era through `initialize`, so
        // configuring it falls back to the handshake set on both ends.
        yield 'client configured modern' => [ProtocolVersion::V2026_07_28, null, $latest];
        yield 'server configured modern' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_06_18];
        yield 'both configured modern' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2026_07_28, $latest];
    }

    #[TestDox('the handshake carries the server identity to the client')]
    public function testServerInfoIsExchanged(): void
    {
        $client = $this->connect($this->serverBuilder()->setInstructions('Be brief.'));

        $this->assertSame('integration-server', $client->getServerInfo()->name);
        $this->assertSame('1.0.0', $client->getServerInfo()->version);
        $this->assertSame('Be brief.', $client->getInstructions());
        $this->assertTrue($client->isConnected());
    }

    #[TestDox('the negotiated revision is unset before the handshake')]
    public function testProtocolVersionIsNullBeforeConnecting(): void
    {
        $this->assertNull($this->clientBuilder()->build()->getProtocolVersion());
    }
}
