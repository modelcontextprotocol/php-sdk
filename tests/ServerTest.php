<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests;

use Mcp\JsonRpc\Handler;
use Mcp\Server;
use Mcp\Server\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

class ServerTest extends TestCase
{
    public function testJsonExceptions()
    {
        $handler = $this->getMockBuilder(Handler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process'])
            ->getMock();

        $handler->expects($this->exactly(2))->method('process')->willReturnOnConsecutiveCalls(
            [['{"jsonrpc":"2.0","id":0,"error":{"code":-32700,"message":"Parse error"}}', []]],
            [['success', []]]
        );

        $transport = $this->getMockBuilder(InMemoryTransport::class)
            ->setConstructorArgs([['foo', 'bar']])
            ->onlyMethods(['send'])
            ->getMock();
        $transport->expects($this->exactly(2))->method('send')->willReturnOnConsecutiveCalls(
            null,
            null
        );

        $server = new Server($handler);
        $server->connect($transport);

        $transport->listen();
    }
}
