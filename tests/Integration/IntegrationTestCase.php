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

use Mcp\Client;
use Mcp\Client\Builder as ClientBuilder;
use Mcp\Server;
use Mcp\Server\Builder as ServerBuilder;
use Mcp\Tests\Integration\Loopback\LoopbackConnection;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that run a real client against a real server.
 *
 * Every other test in the suite mocks one side of the conversation. These run
 * both, so what they cover is the agreement between the two halves rather than
 * either half against an expectation of the other.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function serverBuilder(): ServerBuilder
    {
        return Server::builder()->setServerInfo('integration-server', '1.0.0');
    }

    protected function clientBuilder(): ClientBuilder
    {
        return Client::builder()->setClientInfo('integration-client', '1.0.0');
    }

    /**
     * Connect a client to a server over an in-process loopback.
     *
     * The returned client has completed the handshake, so the negotiated
     * revision and the server info are already readable.
     */
    protected function connect(?ServerBuilder $server = null, ?ClientBuilder $client = null): Client
    {
        $connection = new LoopbackConnection();

        // run() wires the protocol to the transport and returns straight away:
        // the loopback transport has no loop of its own to enter, because the
        // connection drives it from the client's side instead.
        ($server ?? $this->serverBuilder())->build()->run($connection->serverTransport());

        $mcpClient = ($client ?? $this->clientBuilder())->build();
        $mcpClient->connect($connection->clientTransport());

        return $mcpClient;
    }
}
