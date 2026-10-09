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

use Mcp\Exception\ConnectionException;
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
    public function testNegotiatedVersion(?ProtocolVersion $clientVersion, ?ProtocolVersion $serverVersion, ProtocolVersion $expected, bool $handshakeOnly = false, ?ProtocolVersion $fallback = null): void
    {
        $client = $this->clientBuilder();

        if (null !== $clientVersion) {
            $client->setProtocolVersion($clientVersion);
        }

        if (null !== $fallback) {
            $client->setFallbackProtocolVersion($fallback);
        }

        $connected = $this->connect('handshake', $client, self::environment($serverVersion, $handshakeOnly));

        $this->assertSame($expected, $connected->getProtocolVersion());
    }

    /**
     * @return iterable<string, array{0: ?ProtocolVersion, 1: ?ProtocolVersion, 2: ProtocolVersion, 3?: bool, 4?: ProtocolVersion}>
     */
    public static function provideNegotiations(): iterable
    {
        yield 'both unconfigured' => [null, null, ProtocolVersion::latestHandshake()];

        // Whichever end of the supported range it sits at.
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            yield \sprintf('client asks for %s', $version->value) => [$version, null, $version];
        }

        // A pinned server answers with its pin, and the client continues on it.
        yield 'server pins an older revision' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_03_26, ProtocolVersion::V2025_03_26];
        yield 'server pins a newer revision' => [ProtocolVersion::V2024_11_05, ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_11_25];
        yield 'both pin the same revision' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18];

        yield 'client configured modern' => [ProtocolVersion::V2026_07_28, null, ProtocolVersion::V2026_07_28];
        yield 'both configured modern' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2026_07_28, ProtocolVersion::V2026_07_28];

        // The server end still falls back: a handshake-era client offered a
        // revision, and `initialize` cannot answer with a modern one.
        yield 'server configured modern' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_06_18];

        yield 'server without the modern era' => [ProtocolVersion::V2026_07_28, null, ProtocolVersion::V2025_11_25, true];
        yield 'server without the modern era, client falling back further' => [ProtocolVersion::V2026_07_28, null, ProtocolVersion::V2025_06_18, true, ProtocolVersion::V2025_06_18];
        yield 'server without the modern era pinning a revision' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2025_03_26, ProtocolVersion::V2025_03_26, true];
    }

    #[TestDox('falling back to the handshake costs a refusal, not a timeout')]
    public function testFallbackDoesNotWaitOutTheProbe(): void
    {
        $started = microtime(true);
        $client = $this->connect('handshake', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28), self::environment(null, true));

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
        $this->assertLessThan(3, microtime(true) - $started);
    }

    #[TestDox('a modern-only client refuses a server without the modern era')]
    public function testModernOnlyClientRefusesAHandshakeOnlyServer(): void
    {
        $client = $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28)->setFallbackProtocolVersion(null)->setMaxRetries(0)->build();

        try {
            $client->connect($this->transport('handshake', self::environment(null, true)));
            $this->fail('A modern-only client must not connect to a server without the modern era.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('without a handshake fallback', $e->getMessage());
        } finally {
            $client->disconnect();
        }
    }

    #[TestDox('the server identity reaches the client on $_dataName')]
    #[DataProvider('provideEras')]
    public function testServerInfoIsExchanged(ProtocolVersion $era): void
    {
        $client = $this->connect('handshake', $this->clientBuilder()->setProtocolVersion($era));

        $this->assertSame($era, $client->getProtocolVersion());
        $serverInfo = $client->getServerInfo();
        $this->assertNotNull($serverInfo);
        $this->assertSame('integration-server', $serverInfo->name);
        $this->assertSame('1.0.0', $serverInfo->version);
        $this->assertSame('Be brief.', $client->getInstructions());
        $this->assertTrue($client->isConnected());
    }

    #[TestDox('a liveness check works on $_dataName')]
    #[DataProvider('provideEras')]
    public function testPing(ProtocolVersion $era): void
    {
        $client = $this->connect('handshake', $this->clientBuilder()->setProtocolVersion($era));

        $client->ping();

        $this->assertTrue($client->isConnected());
    }

    /**
     * @return array<string, string>
     */
    private static function environment(?ProtocolVersion $serverVersion, bool $handshakeOnly): array
    {
        $env = [];

        if (null !== $serverVersion) {
            $env['MCP_INTEGRATION_PROTOCOL_VERSION'] = $serverVersion->value;
        }

        if ($handshakeOnly) {
            $env['MCP_INTEGRATION_HANDSHAKE_ONLY'] = '1';
        }

        return $env;
    }

    #[TestDox('the handshake carries the server capabilities to the client')]
    public function testServerCapabilitiesAreExchanged(): void
    {
        $capabilities = $this->connect('handshake')->getServerCapabilities();

        // The fixture registers no tools, resources or prompts.
        $this->assertNotNull($capabilities);
        $this->assertTrue($capabilities->logging);
        $this->assertTrue($capabilities->completions);
        $this->assertFalse($capabilities->tools);
        $this->assertFalse($capabilities->prompts);
        $this->assertFalse($capabilities->resources);
    }

    #[TestDox('the server capabilities are unset before the handshake')]
    public function testServerCapabilitiesAreNullBeforeConnecting(): void
    {
        $this->assertNull($this->clientBuilder()->build()->getServerCapabilities());
    }

    #[TestDox('the negotiated revision is unset before the handshake')]
    public function testProtocolVersionIsNullBeforeConnecting(): void
    {
        $this->assertNull($this->clientBuilder()->build()->getProtocolVersion());
    }
}
