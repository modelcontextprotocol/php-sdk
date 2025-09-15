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
use Mcp\Server\NotificationPublisher;
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

        $notificationPublisher = $this->createMock(NotificationPublisher::class);
        $notificationPublisher->expects($this->once())->method('flush');

        $transport = $this->getMockBuilder(InMemoryTransport::class)
            ->setConstructorArgs([['foo', 'bar']])
            ->onlyMethods(['send'])
            ->getMock();
        $transport->expects($this->once())->method('send')->with('success');

        $server = new Server($handler, $notificationPublisher, $logger);
        $server->connect($transport);
    }
}
