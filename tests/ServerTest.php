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

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\JsonRpc\Handler;
use Mcp\Server;
use Mcp\Server\Transport\InMemoryTransport;
use PHPUnit\Framework\MockObject\Stub\Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ServerTest extends TestCase
{
    public function testJsonExceptions()
    {
        $logger = $this->getMockBuilder(NullLogger::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['error'])
            ->getMock();
        $logger->expects($this->once())->method('error');

        $handler = $this->getMockBuilder(Handler::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process'])
            ->getMock();

        $handler->expects($this->exactly(2))->method('process')->willReturnOnConsecutiveCalls(new Exception(new \JsonException('foobar')), ['success']);

        $transport = $this->getMockBuilder(InMemoryTransport::class)
            ->setConstructorArgs([['foo', 'bar']])
            ->onlyMethods(['send'])
            ->getMock();
        $transport->expects($this->once())->method('send')->with('success');

        $registry = $this->createMock(ReferenceProviderInterface::class);

        $server = new Server($handler, $registry, $logger);
        $server->connect($transport);
    }

    public function testGetRegistry()
    {
        $handler = $this->createMock(Handler::class);
        $registry = $this->createMock(ReferenceProviderInterface::class);
        $logger = new NullLogger();

        $server = new Server($handler, $registry, $logger);

        $this->assertSame($registry, $server->getRegistry());
    }
}
