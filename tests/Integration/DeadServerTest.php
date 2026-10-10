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
use Mcp\Exception\ExceptionInterface;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What a server process that dies leaves behind on the client.
 *
 * @see Fixture/dead_server.php for the server under test
 */
final class DeadServerTest extends IntegrationTestCase
{
    #[DataProvider('provideEras')]
    #[TestDox('a server exiting mid-call leaves the client disconnected on $_dataName')]
    public function testExitedServerDisconnectsTheClient(ProtocolVersion $version): void
    {
        $client = $this->connect('dead_server', $this->clientBuilder()->setProtocolVersion($version));

        try {
            $client->callTool('exit');
            $this->fail('A call the server dies in must fail.');
        } catch (ExceptionInterface) {
        }

        $this->assertFalse($client->isConnected());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Client is not connected.');

        $client->callTool('fast');
    }
}
