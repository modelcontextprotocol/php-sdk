<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Logger;

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Test for simplified ClientLogger PSR-3 compliance.
 */
final class ClientLoggerTest extends TestCase
{
    public function testLog()
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $session->expects($this->once())->method('get')->willReturn('info');
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->once())->method('log')->with(LoggingLevel::Notice, 'test');

        $logger = new ClientLogger($clientGateway, $session);
        $logger->notice('test');
    }

    public function testLogFilter()
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $session->expects($this->once())->method('get')->willReturn('info');
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->never())->method('log');

        $logger = new ClientLogger($clientGateway, $session);
        $logger->debug('test');
    }

    public function testLogFilterSameLevel()
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $session->expects($this->once())->method('get')->willReturn('info');
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->once())->method('log');

        $logger = new ClientLogger($clientGateway, $session);
        $logger->info('test');
    }

    public function testLogWithInvalidLevel()
    {
        $session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $session->expects($this->any())->method('get')->willReturn('info');
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->never())->method('log');

        $logger = new ClientLogger($clientGateway, $session);
        $logger->log('foo', 'test');
    }
}
