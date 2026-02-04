<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

class SessionSubscriptionManagerTest extends TestCase
{
    private SessionSubscriptionManager $subscriptionManager;
    private LoggerInterface&MockObject $logger;
    private Protocol&MockObject $protocol;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->protocol = $this->createMock(Protocol::class);
        $this->subscriptionManager = new SessionSubscriptionManager($this->logger);
    }

    #[TestDox('Subscribing to a resource sends update notifications')]
    public function testSubscribeAndSendsNotification(): void
    {
        // Arrange
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $uri = 'test://resource';

        $session->method('get')
            ->with('resource_subscriptions', [])
            ->willReturnOnConsecutiveCalls(
                [],
                [$uri => true]
            );

        $session->expects($this->once())->method('set')->with('resource_subscriptions', [$uri => true]);
        $session->expects($this->once())->method('save');

        // Act
        $this->subscriptionManager->subscribe($session, $uri);

        // Assert
        $this->protocol->expects($this->once())
            ->method('sendNotification')
            ->with($this->isInstanceOf(ResourceUpdatedNotification::class));

        $this->subscriptionManager->notifyResourceChanged($this->protocol, $session, $uri);
    }

    #[TestDox('Unsubscribe from a resource')]
    public function testUnsubscribeFromAResource(): void
    {
        // Arrange
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $uri = 'test://resource';

        $session->method('get')
            ->with('resource_subscriptions', [])
            ->willReturnOnConsecutiveCalls(
                [],
                [$uri => true],
                [$uri => true],
            );

        $session->expects($this->exactly(2))->method('set');
        $session->expects($this->exactly(2))->method('save');

        // Act
        $this->subscriptionManager->subscribe($session, $uri);

        $this->protocol->expects($this->once())->method('sendNotification');
        $this->subscriptionManager->notifyResourceChanged($this->protocol, $session, $uri);

        $this->subscriptionManager->unsubscribe($session, $uri);
    }

    #[TestDox('Unsubscribing from a resource verifies that no notification is sent')]
    public function testUnsubscribeDoesNotSendNotifications(): void
    {
        // Arrange
        $protocol = $this->createMock(Protocol::class);
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $uri = 'test://resource';

        $session->method('get')
            ->with('resource_subscriptions', [])
            ->willReturnOnConsecutiveCalls(
                [],
                [$uri => true],
                []
            );

        $session->expects($this->exactly(2))->method('set');
        $session->expects($this->exactly(2))->method('save');

        // Act
        $this->subscriptionManager->subscribe($session, $uri);
        $this->subscriptionManager->unsubscribe($session, $uri);

        // Assert
        $protocol->expects($this->never())->method('sendNotification');
        $this->subscriptionManager->notifyResourceChanged($protocol, $session, $uri);
    }

    #[TestDox('Logs error when notification fails to send')]
    public function testLogsErrorWhenNotificationFails(): void
    {
        // Arrange
        $protocol = $this->createMock(Protocol::class);
        $session = $this->createMock(SessionInterface::class);
        $uuid = Uuid::v4();
        $session->method('getId')->willReturn($uuid);
        $uri = 'test://resource';

        $session->method('get')
            ->with('resource_subscriptions', [])
            ->willReturnOnConsecutiveCalls(
                [],
                [$uri => true]
            );

        $session->expects($this->once())->method('set')->with('resource_subscriptions', [$uri => true]);
        $session->expects($this->once())->method('save');

        $this->subscriptionManager->subscribe($session, $uri);

        // Create a concrete exception that implements InvalidArgumentException
        $exception = new class('Cache error') extends \Exception implements InvalidArgumentException {};

        $protocol->expects($this->once())
            ->method('sendNotification')
            ->willThrowException($exception);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Error sending resource notification to session',
                $this->callback(static function ($context) use ($uuid, $uri, $exception) {
                    return $context['session_id'] === (string) $uuid
                        && $context['uri'] === $uri
                        && $context['exception'] === $exception;
                })
            );

        try {
            // Act
            $this->subscriptionManager->notifyResourceChanged($protocol, $session, $uri);

            $this->fail('Expected an exception to be thrown.');
        } catch (InvalidArgumentException $e) {
            // Assert
            $this->assertSame($exception, $e);

            return;
        }
    }
}
