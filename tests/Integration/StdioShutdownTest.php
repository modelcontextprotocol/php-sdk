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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * @see Fixture/handshake.php for the server under test
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
#[Group('os')]
final class StdioShutdownTest extends TestCase
{
    private const TIMEOUT = 5;

    #[TestDox('a server exits by itself once the client closes its pipe')]
    public function testServerExitsWhenTheClientClosesItsPipe(): void
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/Fixture/handshake.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        try {
            fwrite($pipes[0], json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-11-25',
                    'capabilities' => new \stdClass(),
                    'clientInfo' => ['name' => 'integration-client', 'version' => '1.0.0'],
                ],
            ], \JSON_THROW_ON_ERROR)."\n");

            $read = [$pipes[1]];
            $write = $except = null;
            $this->assertSame(1, stream_select($read, $write, $except, self::TIMEOUT), 'the server must answer');
            $this->assertStringContainsString('"id":1', (string) fgets($pipes[1]));

            fclose($pipes[0]);

            $deadline = microtime(true) + self::TIMEOUT;
            do {
                $status = proc_get_status($process);
                usleep(10000);
            } while ($status['running'] && microtime(true) < $deadline);

            $this->assertFalse($status['running'], 'the server must exit once its input ends');
            $this->assertSame(0, $status['exitcode']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
