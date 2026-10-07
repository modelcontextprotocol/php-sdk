<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Client;

use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Tests\Interop\NodeTool;
use Mcp\Tests\Support\ServerProcess;
use Symfony\Component\Process\Process;

final class HttpEverythingServerTest extends EverythingServerTestCase
{
    private static ?Process $server = null;
    private static string $endpoint;

    public static function setUpBeforeClass(): void
    {
        // Started here rather than in transport() so a server that never comes
        // up fails with its own output instead of as a client timeout.
        $port = ServerProcess::findFreePort();

        self::$server = new Process([NodeTool::path('mcp-server-everything'), 'streamableHttp'], env: ['PORT' => (string) $port]);
        self::$server->start();

        $deadline = microtime(true) + 10;
        while (!@fsockopen('127.0.0.1', $port, $errno, $error, 0.1)) {
            if (!self::$server->isRunning() || microtime(true) > $deadline) {
                self::fail(\sprintf('The everything server did not start: %s', self::$server->getErrorOutput()));
            }

            usleep(100_000);
        }

        self::$endpoint = \sprintf('http://127.0.0.1:%d/mcp', $port);

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        ServerProcess::stop(self::$server);
        self::$server = null;
    }

    protected static function transport(): TransportInterface
    {
        return new HttpTransport(self::$endpoint);
    }

    protected static function receivesUnrelatedServerRequests(): bool
    {
        return false;
    }
}
