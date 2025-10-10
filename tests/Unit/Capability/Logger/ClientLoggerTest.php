<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Capability\Logger;

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Server\Handler\NotificationHandler;
use Mcp\Server\NotificationSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test for simplified ClientLogger PSR-3 compliance.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class ClientLoggerTest extends TestCase
{
    private LoggerInterface&MockObject $fallbackLogger;

    protected function setUp(): void
    {
        $this->fallbackLogger = $this->createMock(LoggerInterface::class);
    }

    public function testImplementsPsr3LoggerInterface(): void
    {
        $logger = $this->createClientLogger();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testAlwaysLogsToFallbackLogger(): void
    {
        $this->fallbackLogger
            ->expects($this->once())
            ->method('log')
            ->with('info', 'Test message', ['key' => 'value']);

        $logger = $this->createClientLogger();
        $logger->info('Test message', ['key' => 'value']);
    }

    public function testBasicLoggingMethodsWork(): void
    {
        $logger = $this->createClientLogger();

        // Test all PSR-3 methods exist and can be called
        $this->fallbackLogger->expects($this->exactly(8))->method('log');

        $logger->emergency('emergency');
        $logger->alert('alert');
        $logger->critical('critical');
        $logger->error('error');
        $logger->warning('warning');
        $logger->notice('notice');
        $logger->info('info');
        $logger->debug('debug');
    }

    public function testHandlesMcpSendGracefully(): void
    {
        // Expect fallback logger to be called for original message
        $this->fallbackLogger
            ->expects($this->once())
            ->method('log')
            ->with('info', 'Test message', []);

        // May also get error log if MCP send fails (which it likely will without transport)
        $this->fallbackLogger
            ->expects($this->atMost(1))
            ->method('error');

        $logger = $this->createClientLogger();
        $logger->info('Test message');
    }

    private function createClientLogger(): ClientLogger
    {
        // Create minimal working NotificationSender for testing
        // Using a minimal ReferenceRegistryInterface mock just to construct NotificationHandler
        $registry = $this->createMock(ReferenceRegistryInterface::class);
        $notificationHandler = NotificationHandler::make($registry);
        $notificationSender = new NotificationSender($notificationHandler, null);

        return new ClientLogger(
            $notificationSender,
            $this->fallbackLogger
        );
    }
}
