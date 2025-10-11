<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Server;

use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\Handler\NotificationHandler;
use Mcp\Server\NotificationSender;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class NotificationSenderTest extends TestCase
{
    private NotificationHandler $notificationHandler;
    /** @var TransportInterface<mixed>&MockObject */
    private TransportInterface&MockObject $transport;
    private LoggerInterface&MockObject $logger;
    private ReferenceProviderInterface&MockObject $referenceProvider;
    private NotificationSender $sender;

    protected function setUp(): void
    {
        $this->referenceProvider = $this->createMock(ReferenceProviderInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create real NotificationHandler with mocked dependencies
        $this->notificationHandler = NotificationHandler::make($this->referenceProvider);

        $this->sender = new NotificationSender(
            $this->notificationHandler,
            null,
            $this->logger
        );
    }

    public function testSetTransport(): void
    {
        // Configure logging to be enabled
        $this->referenceProvider
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(true);

        $this->referenceProvider
            ->method('getLoggingMessageNotificationLevel')
            ->willReturn(LoggingLevel::Info);

        // Setting transport should not throw any exceptions
        $this->sender->setTransport($this->transport);

        // Verify we can send after setting transport (integration test)
        $this->transport
            ->expects($this->once())
            ->method('send')
            ->with($this->isType('string'), []);

        $this->sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }

    public function testSendWithoutTransportThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No transport configured for notification sending');

        $this->sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }

    public function testSendSuccessfulNotification(): void
    {
        // Configure logging to be enabled
        $this->referenceProvider
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(true);

        $this->referenceProvider
            ->method('getLoggingMessageNotificationLevel')
            ->willReturn(LoggingLevel::Info);

        $this->sender->setTransport($this->transport);

        // Verify that transport send is called when we have a valid setup
        $this->transport
            ->expects($this->once())
            ->method('send')
            ->with($this->isType('string'), []);

        $this->sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }

    public function testSendNullNotificationDoesNotCallTransport(): void
    {
        $this->sender->setTransport($this->transport);

        // Configure to disable logging so handler returns null
        $this->referenceProvider
            ->expects($this->once())
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(false);

        $this->transport
            ->expects($this->never())
            ->method('send');

        $this->sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }

    public function testSendHandlerFailureGracefullyHandled(): void
    {
        $this->sender->setTransport($this->transport);

        // Make logging disabled so handler fails gracefully (returns null)
        $this->referenceProvider
            ->expects($this->once())
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(false);

        // Transport should never be called when notification creation fails
        $this->transport
            ->expects($this->never())
            ->method('send');

        // Expect a warning to be logged about failed notification creation
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with('Failed to create notification', ['method' => 'notifications/message']);

        // This should not throw an exception - it should fail gracefully
        $this->sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }

    public function testSendTransportExceptionThrowsRuntimeException(): void
    {
        $exception = new \Exception('Transport error');

        $this->sender->setTransport($this->transport);

        // Configure successful logging
        $this->referenceProvider
            ->expects($this->once())
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(true);

        $this->referenceProvider
            ->expects($this->once())
            ->method('getLoggingMessageNotificationLevel')
            ->willReturn(LoggingLevel::Info);

        $this->transport
            ->expects($this->once())
            ->method('send')
            ->with($this->isType('string'), [])
            ->willThrowException($exception);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to send notification: Transport error');

        $this->sender->send('notifications/message', [
            'level' => 'info',
            'data' => 'test message',
        ]);
    }

    public function testConstructorWithTransport(): void
    {
        // Configure logging to be enabled
        $this->referenceProvider
            ->method('isLoggingMessageNotificationEnabled')
            ->willReturn(true);

        $this->referenceProvider
            ->method('getLoggingMessageNotificationLevel')
            ->willReturn(LoggingLevel::Info);

        $sender = new NotificationSender(
            $this->notificationHandler,
            $this->transport,
            $this->logger
        );

        // Verify the sender can send notifications when constructed with transport
        $this->transport
            ->expects($this->once())
            ->method('send')
            ->with($this->isType('string'), []);

        $sender->send('notifications/message', ['level' => 'info', 'data' => 'test']);
    }
}
