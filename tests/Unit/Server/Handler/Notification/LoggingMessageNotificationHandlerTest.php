<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Handler\Notification;

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Server\Handler\Notification\LoggingMessageNotificationHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
class LoggingMessageNotificationHandlerTest extends TestCase
{
    private LoggingMessageNotificationHandler $handler;
    private ReferenceRegistryInterface&MockObject $registry;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ReferenceRegistryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new LoggingMessageNotificationHandler(
            $this->registry,
            $this->logger
        );
    }

    public function testHandleNotificationCreation(): void
    {
        $this->registry
            ->expects($this->exactly(2))
            ->method('isLoggingEnabled')
            ->willReturn(true);

        $this->registry
            ->expects($this->exactly(2))
            ->method('getLoggingLevel')
            ->willReturnOnConsecutiveCalls(LoggingLevel::Info, LoggingLevel::Debug);

        // Test with LoggingLevel enum
        $params1 = [
            'level' => LoggingLevel::Error,
            'data' => 'Test error message',
            'logger' => 'TestLogger',
        ];
        $notification1 = $this->handler->handle(LoggingMessageNotification::getMethod(), $params1);
        $this->assertInstanceOf(LoggingMessageNotification::class, $notification1);
        /* @var LoggingMessageNotification $notification1 */
        $this->assertEquals(LoggingLevel::Error, $notification1->level);
        $this->assertEquals($params1['data'], $notification1->data);
        $this->assertEquals($params1['logger'], $notification1->logger);

        // Test with string level conversion
        $params2 = [
            'level' => 'warning',
            'data' => 'String level test',
        ];
        $notification2 = $this->handler->handle(LoggingMessageNotification::getMethod(), $params2);
        $this->assertInstanceOf(LoggingMessageNotification::class, $notification2);
        /* @var LoggingMessageNotification $notification2 */
        $this->assertEquals(LoggingLevel::Warning, $notification2->level);
        $this->assertEquals($params2['data'], $notification2->data);
        $this->assertNull($notification2->logger);
    }

    public function testValidationAndErrors(): void
    {
        // Test missing level parameter
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter "level" for logging notification');
        $this->handler->handle(LoggingMessageNotification::getMethod(), ['data' => 'Missing level parameter']);
    }

    public function testValidateRequiredParameterData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required parameter "data" for logging notification');
        $this->handler->handle(LoggingMessageNotification::getMethod(), ['level' => LoggingLevel::Info]);
    }

    public function testLoggingDisabledRejectsMessages(): void
    {
        $this->registry
            ->expects($this->once())
            ->method('isLoggingEnabled')
            ->willReturn(false);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Logging is disabled, skipping log message');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Logging capability is not enabled');

        $params = [
            'level' => LoggingLevel::Error,
            'data' => 'This should be rejected',
        ];

        $this->handler->handle(LoggingMessageNotification::getMethod(), $params);
    }

    public function testLogLevelFiltering(): void
    {
        // Test equal level is allowed
        $this->registry
            ->expects($this->exactly(3))
            ->method('isLoggingEnabled')
            ->willReturn(true);

        $this->registry
            ->expects($this->exactly(3))
            ->method('getLoggingLevel')
            ->willReturnOnConsecutiveCalls(LoggingLevel::Warning, LoggingLevel::Warning, LoggingLevel::Error);

        // Equal level should be allowed
        $params1 = ['level' => LoggingLevel::Warning, 'data' => 'Warning message at threshold'];
        $notification1 = $this->handler->handle(LoggingMessageNotification::getMethod(), $params1);
        $this->assertInstanceOf(LoggingMessageNotification::class, $notification1);
        /* @var LoggingMessageNotification $notification1 */
        $this->assertEquals(LoggingLevel::Warning, $notification1->level);

        // Higher severity should be allowed
        $params2 = ['level' => LoggingLevel::Critical, 'data' => 'Critical message above threshold'];
        $notification2 = $this->handler->handle(LoggingMessageNotification::getMethod(), $params2);
        $this->assertInstanceOf(LoggingMessageNotification::class, $notification2);
        /* @var LoggingMessageNotification $notification2 */
        $this->assertEquals(LoggingLevel::Critical, $notification2->level);

        // Lower severity should be rejected
        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('Log level warning is below current threshold error, skipping');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Log level is below current threshold');

        $params3 = ['level' => LoggingLevel::Warning, 'data' => 'Warning message below threshold'];
        $this->handler->handle(LoggingMessageNotification::getMethod(), $params3);
    }

    public function testErrorHandling(): void
    {
        // Test invalid log level
        $this->expectException(\ValueError::class);
        $this->handler->handle(LoggingMessageNotification::getMethod(), [
            'level' => 'invalid_level',
            'data' => 'Test data',
        ]);
    }

    public function testUnsupportedMethodThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Handler does not support method: unsupported/method');
        $this->handler->handle('unsupported/method', ['level' => LoggingLevel::Info, 'data' => 'Test data']);
    }

    public function testNotificationSerialization(): void
    {
        $this->registry
            ->expects($this->once())
            ->method('isLoggingEnabled')
            ->willReturn(true);

        $this->registry
            ->expects($this->once())
            ->method('getLoggingLevel')
            ->willReturn(LoggingLevel::Debug);

        $params = [
            'level' => LoggingLevel::Info,
            'data' => 'Serialization test',
            'logger' => 'TestLogger',
        ];

        $notification = $this->handler->handle(LoggingMessageNotification::getMethod(), $params);
        $serialized = $notification->jsonSerialize();

        $this->assertEquals('2.0', $serialized['jsonrpc']);
        $this->assertEquals('notifications/message', $serialized['method']);
        $this->assertEquals('info', $serialized['params']['level']);
        $this->assertEquals('Serialization test', $serialized['params']['data']);
        $this->assertEquals('TestLogger', $serialized['params']['logger']);
    }
}
