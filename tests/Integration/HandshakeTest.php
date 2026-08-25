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
 * What the two sides settle on before anything else can happen.
 *
 * @see Fixture/handshake.php for the server under test
 */
final class HandshakeTest extends IntegrationTestCase
{
    #[TestDox('client and server agree on a revision')]
    #[DataProvider('provideNegotiations')]
    public function testNegotiatedVersion(?ProtocolVersion $clientVersion, ?ProtocolVersion $serverVersion, ProtocolVersion $expected): void
    {
        $client = $this->clientBuilder();
        if (null !== $clientVersion) {
            $client->setProtocolVersion($clientVersion);
        }

        $connected = $this->connect(
            'handshake',
            $client,
            null !== $serverVersion ? ['MCP_INTEGRATION_PROTOCOL_VERSION' => $serverVersion->value] : [],
        );

        $this->assertSame($expected, $connected->getProtocolVersion());
    }

    /**
     * @return iterable<string, array{?ProtocolVersion, ?ProtocolVersion, ProtocolVersion}>
     */
    public static function provideNegotiations(): iterable
    {
        $latest = ProtocolVersion::latestHandshake();

        yield 'both unconfigured' => [null, null, $latest];

        // Whichever end of the supported range it sits at.
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield \sprintf('client asks for %s', $version->value) => [$version, null, $version];
        }

        // A pinned server answers with its pin, and the client continues on it.
        yield 'server pins an older revision' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_03_26, ProtocolVersion::V2025_03_26];
        yield 'server pins a newer revision' => [ProtocolVersion::V2024_11_05, ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25];
        yield 'both pin the same revision' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18];

        // A modern client does not negotiate at all: it skips the handshake and
        // states its revision on every request, so what it was configured with
        // is what it reports. This server never answers `server/discover`, so
        // there is nothing to reconcile against either.
        yield 'client configured modern' => [ProtocolVersion::V2026_07_28, null, ProtocolVersion::V2026_07_28];
        yield 'both configured modern' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2026_07_28, ProtocolVersion::V2026_07_28];

        // The server end still falls back: a handshake-era client offered a
        // revision, and `initialize` cannot answer with a modern one.
        yield 'server configured modern' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_06_18];
    }

    #[TestDox('the handshake carries the server identity to the client')]
    public function testServerInfoIsExchanged(): void
    {
        $client = $this->connect('handshake');

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
