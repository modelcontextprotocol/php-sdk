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
 * Two clients, two protocol eras, one URL.
 *
 * {@see StatelessClientTest} proves the modern client reaches this example and
 * the Inspector snapshots prove a handshake-era one reaches the others. This
 * proves the thing neither of them can on its own: that both reach the *same*
 * endpoint, with nothing for the client to choose and nothing for the operator
 * to mount twice.
 */
final class DualEraEndpointTest extends DualEraExampleTestCase
{
    protected const ANSWERS = ['name' => 'Ada'];

    protected static function server(): string
    {
        return __DIR__.'/../../examples/server/stateless-lifecycle/server.php';
    }

    protected static function portBase(): int
    {
        return 9200;
    }

    #[DataProvider('provideEras')]
    #[TestDox('a client on $_dataName connects to the one endpoint')]
    public function testConnects(ProtocolVersion $era): void
    {
        $client = $this->connect($era);

        $this->assertTrue($client->isConnected());
        $this->assertSame($era, $client->getProtocolVersion());

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('a client on $_dataName sees the same tools')]
    public function testListsTheSameTools(ProtocolVersion $era): void
    {
        $client = $this->connect($era);

        $names = array_map(static fn ($tool): string => $tool->name, $client->listTools()->tools);
        sort($names);

        // One registry behind both legs, so the catalogue cannot drift.
        $this->assertSame(['get_weather', 'greet', 'reindex'], $names);

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('a client on $_dataName gets the same answer from the same tool')]
    public function testCallsTheSameTool(ProtocolVersion $era): void
    {
        $client = $this->connect($era);

        $this->assertSame(
            'It is 17°C and cloudy in Munich.',
            self::text($client->callTool('get_weather', ['city' => 'Munich'])),
        );

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('a tool that has to ask the user completes on $_dataName')]
    public function testAsksForInput(ProtocolVersion $era): void
    {
        // The one place the eras genuinely differ on the wire: the handshake era
        // is asked mid-call over its session's stream, the modern era is handed
        // the question as a result and retries. The example forks on exactly
        // that; the caller here does not.
        $client = $this->connect($era, elicitation: true);

        $this->assertSame('Hello, Ada!', self::text($client->callTool('greet', [])));

        $client->disconnect();
    }

    #[TestDox('both eras are served in turn without restarting anything')]
    public function testBothErasAgainstOneRunningServer(): void
    {
        $handshake = $this->connect(ProtocolVersion::V2025_11_25);
        $modern = $this->connect(ProtocolVersion::V2026_07_28);

        // Interleaved on purpose: the handshake client's session stays open
        // while the modern one is served, and neither disturbs the other.
        $first = self::text($handshake->callTool('get_weather', ['city' => 'Berlin']));
        $second = self::text($modern->callTool('get_weather', ['city' => 'Berlin']));
        $third = self::text($handshake->callTool('get_weather', ['city' => 'Berlin']));

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);

        $handshake->disconnect();
        $modern->disconnect();
    }
}
