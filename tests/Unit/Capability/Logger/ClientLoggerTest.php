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
use PHPUnit\Framework\TestCase;

/**
 * Test for simplified ClientLogger PSR-3 compliance.
 */
final class ClientLoggerTest extends TestCase
{
    public function testLog(): void
    {
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->once())->method('log')->with(LoggingLevel::Notice, 'test');

        $logger = new ClientLogger($clientGateway);
        $logger->notice('test');
    }

    public function testLogWithInvalidLevel(): void
    {
        $clientGateway = $this->getMockBuilder(ClientGateway::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $clientGateway->expects($this->never())->method('log');

        $logger = new ClientLogger($clientGateway);
        $logger->log('foo', 'test');
    }
}
