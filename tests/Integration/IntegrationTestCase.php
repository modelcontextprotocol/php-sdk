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

use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Client;
use Mcp\Client\Exception\ConnectionException;
use Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that run a real client against a real server process.
 *
 * Every other test in the suite mocks one side of the conversation. These run
 * both, wired the way `examples/client` wires them, so what they cover is the
 * agreement between the two halves. The servers live in {@see Fixture}, one
 * script per scenario.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Both sides answer immediately, so anything reaching this is a deadlock.
     * Far below the SDK's two-minute default, to fail rather than hang.
     */
    private const TIMEOUT = 5;

    private ?Client $client = null;

    protected function clientBuilder(): ClientBuilder
    {
        return Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setInitTimeout(self::TIMEOUT)
            ->setRequestTimeout(self::TIMEOUT);
    }

    /**
     * Spawn a fixture server and connect a client to it.
     *
     * The returned client has completed the handshake.
     *
     * @param string                $fixture basename of a script in {@see Fixture}
     * @param array<string, string> $env     added to the server process environment
     */
    protected function connect(string $fixture, ?ClientBuilder $client = null, array $env = []): Client
    {
        $this->client = ($client ?? $this->clientBuilder())->build();

        try {
            $this->client->connect($this->transport($fixture, $env));
        } catch (ConnectionException $e) {
            // The transport discards the child's stderr, so a fixture dying on
            // startup arrives here as a bare timeout.
            $this->fail(\sprintf('Could not connect to fixture server "%s": %s. Run `%s %s` to see why.', $fixture, $e->getMessage(), \PHP_BINARY, self::script($fixture)));
        }

        return $this->client;
    }

    /**
     * A transport that will spawn a fixture server, without connecting it.
     *
     * Tests that assert on a failing connection need the transport by itself,
     * since {@see self::connect()} turns that failure into a test failure.
     *
     * @param array<string, string> $env added to the server process environment
     */
    protected function transport(string $fixture, array $env = []): StdioTransport
    {
        return new StdioTransport(
            command: \PHP_BINARY,
            args: [self::script($fixture)],
            // proc_open() replaces the environment rather than adding to it.
            env: [] === $env ? null : array_merge(getenv(), $env),
        );
    }

    private static function script(string $fixture): string
    {
        return __DIR__.'/Fixture/'.$fixture.'.php';
    }

    protected function tearDown(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }
}
