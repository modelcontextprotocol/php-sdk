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

use Mcp\Event\ErrorEvent;
use Mcp\Event\NotificationEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class ProtocolTest extends TestCase
{
    private MockObject&SessionFactoryInterface $sessionFactory;
    private MockObject&SessionStoreInterface $sessionStore;
    /** @var MockObject&TransportInterface<mixed> */
    private MockObject&TransportInterface $transport;

    protected function setUp(): void
    {
        $this->sessionFactory = $this->createMock(SessionFactoryInterface::class);
        $this->sessionStore = $this->createMock(SessionStoreInterface::class);
        $this->transport = $this->createMock(TransportInterface::class);
    }

    #[TestDox('A single notification can be handled by multiple handlers')]
    public function testNotificationHandledByMultipleHandlers(): void
    {
        $handlerA = $this->createMock(NotificationHandlerInterface::class);
        $handlerA->method('supports')->willReturn(true);
        $handlerA->expects($this->once())->method('handle');

        $handlerB = $this->createMock(NotificationHandlerInterface::class);
        $handlerB->method('supports')->willReturn(false);
        $handlerB->expects($this->never())->method('handle');

        $handlerC = $this->createMock(NotificationHandlerInterface::class);
        $handlerC->method('supports')->willReturn(true);
        $handlerC->expects($this->once())->method('handle');

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handlerA, $handlerB, $handlerC],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
    }

    #[TestDox('A single request is handled only by the first matching handler')]
    public function testRequestHandledByFirstMatchingHandler(): void
    {
        $handlerA = $this->createMock(RequestHandlerInterface::class);
        $handlerA->method('supports')->willReturn(true);
        $handlerA->expects($this->once())->method('handle')->willReturn(new Response(1, ['result' => 'success']));

        $handlerB = $this->createMock(RequestHandlerInterface::class);
        $handlerB->method('supports')->willReturn(false);
        $handlerB->expects($this->never())->method('handle');

        $handlerC = $this->createMock(RequestHandlerInterface::class);
        $handlerC->method('supports')->willReturn(true);
        $handlerC->expects($this->never())->method('handle');

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);
        $session->method('getId')->willReturn(Uuid::v4());

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [$handlerA, $handlerB, $handlerC],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );

        // Check that the response was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('result', $message);
    }

    #[TestDox('Initialize request must not have a session ID')]
    public function testInitializeRequestWithSessionIdReturnsError(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'session ID MUST NOT be sent');
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2024-11-05", "capabilities": {}, "clientInfo": {"name": "test", "version": "1.0"}}}',
            $sessionId
        );
    }

    #[TestDox('Initialize request must not be part of a batch')]
    public function testInitializeRequestInBatchReturnsError(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'MUST NOT be part of a batch');
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->processInput(
            $this->transport,
            '[{"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {"protocolVersion": "2024-11-05", "capabilities": {}, "clientInfo": {"name": "test", "version": "1.0"}}}, {"jsonrpc": "2.0", "method": "ping", "id": 2}]',
            null
        );
    }

    #[TestDox('Non-initialize requests require a session ID')]
    public function testNonInitializeRequestWithoutSessionIdReturnsError(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'session id is REQUIRED');
                }),
                $this->callback(static function ($context) {
                    return isset($context['status_code']) && 400 === $context['status_code'];
                })
            );

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            null
        );
    }

    #[TestDox('Non-existent session ID returns error')]
    public function testNonExistentSessionIdReturnsError(): void
    {
        $this->sessionStore->method('exists')->willReturn(false);

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'Session not found or has expired');
                }),
                $this->callback(static function ($context) {
                    return isset($context['status_code']) && 404 === $context['status_code'];
                })
            );

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );
    }

    #[TestDox('Invalid JSON returns parse error')]
    public function testInvalidJsonReturnsParseError(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(static function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && Error::PARSE_ERROR === $decoded['error']['code'];
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->processInput(
            $this->transport,
            'invalid json',
            null
        );
    }

    #[TestDox('Invalid message structure returns error')]
    public function testInvalidMessageStructureReturnsError(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "params": {}}',
            $sessionId
        );

        // Check that the error was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INVALID_REQUEST, $message['error']['code']);
    }

    #[TestDox('Request without handler returns method not found error')]
    public function testRequestWithoutHandlerReturnsMethodNotFoundError(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
            $sessionId
        );

        // Check that the error was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::METHOD_NOT_FOUND, $message['error']['code']);
        $this->assertStringContainsString('No handler found', $message['error']['message']);
    }

    #[TestDox('Handler throwing InvalidArgumentException returns invalid params error')]
    public function testHandlerInvalidArgumentReturnsInvalidParamsError(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \InvalidArgumentException('Invalid parameter'));

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );

        // Check that the error was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INVALID_PARAMS, $message['error']['code']);
        $this->assertStringContainsString('Invalid parameter', $message['error']['message']);
    }

    #[TestDox('Handler throwing unexpected exception returns internal error')]
    public function testHandlerUnexpectedExceptionReturnsInternalError(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \RuntimeException('Unexpected error'));

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );

        // Check that the error was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INTERNAL_ERROR, $message['error']['code']);
        $this->assertStringContainsString('Unexpected error', $message['error']['message']);
    }

    #[TestDox('Notification handler exceptions are caught and logged')]
    public function testNotificationHandlerExceptionsAreCaught(): void
    {
        $handler = $this->createMock(NotificationHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \RuntimeException('Handler error'));

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );

        $this->expectNotToPerformAssertions();
    }

    #[TestDox('Successful request returns response with session ID')]
    public function testSuccessfulRequestReturnsResponseWithSessionId(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['status' => 'ok']));

        $sessionId = Uuid::v4();
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn($sessionId);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        // The protocol now queues responses instead of sending them directly
        // save() is called once during processInput and once during consumeOutgoingMessages
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );

        // Check that the response was queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('result', $message);
        $this->assertEquals(['status' => 'ok'], $message['result']);
    }

    #[TestDox('Batch requests are processed and send multiple responses')]
    public function testBatchRequestsAreProcessed(): void
    {
        $handlerA = $this->createMock(RequestHandlerInterface::class);
        $handlerA->method('supports')->willReturn(true);
        $handlerA->method('handle')->willReturnCallback(static function ($request) {
            return Response::fromArray([
                'jsonrpc' => '2.0',
                'id' => $request->getId(),
                'result' => ['method' => $request::getMethod()],
            ]);
        });

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        // Configure session mock for queue operations
        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                return $queue;
            }

            return $default;
        });

        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // The protocol now queues responses instead of sending them directly
        $session->expects($this->exactly(2))
            ->method('save');

        $protocol = new Protocol(
            requestHandlers: [$handlerA],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '[{"jsonrpc": "2.0", "method": "tools/list", "id": 1}, {"jsonrpc": "2.0", "method": "prompts/list", "id": 2}]',
            $sessionId
        );

        // Check that both responses were queued in the session
        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(2, $outgoing);

        foreach ($outgoing as $outgoingMessage) {
            $message = json_decode($outgoingMessage['message'], true);
            $this->assertArrayHasKey('result', $message);
        }
    }

    #[TestDox('Session is saved after processing')]
    public function testSessionIsSavedAfterProcessing(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $session->expects($this->once())->method('save');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
    }

    #[TestDox('Destroy session removes session from store')]
    public function testDestroySessionRemovesSession(): void
    {
        $sessionId = Uuid::v4();

        $this->sessionStore->expects($this->once())
            ->method('destroy')
            ->with($sessionId);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->destroySession($sessionId);
    }

    #[TestDox('RequestEvent is dispatched when a request is received')]
    public function testRequestEventIsDispatched(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['result' => 'success']));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should have RequestEvent (and ResponseEvent)
        $this->assertGreaterThanOrEqual(1, \count($capturedEvents));
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertSame($session, $capturedEvents[0]->getSession());
        $this->assertSame('ping', $capturedEvents[0]->getMethod());
    }

    #[TestDox('RequestEvent modification is used by handler')]
    public function testRequestEventModificationIsUsed(): void
    {
        $handlerReceivedRequest = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) {
                if ($event instanceof RequestEvent) {
                    // Simulate a listener modifying the request
                    $originalRequest = $event->getRequest();

                    // Create a modified CallToolRequest with different name but same ID
                    $modifiedRequest = CallToolRequest::fromArray([
                        'jsonrpc' => '2.0',
                        'id' => $originalRequest->getId(),
                        'method' => 'tools/call',
                        'params' => [
                            'name' => 'modified_tool',
                            'arguments' => ['modified' => true],
                        ],
                    ]);

                    $event->setRequest($modifiedRequest);
                }

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler
            ->method('handle')
            ->willReturnCallback(static function ($request) use (&$handlerReceivedRequest) {
                $handlerReceivedRequest = $request;

                return new Response(1, ['result' => 'success']);
            });

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "tools/call", "id": 1, "params": {"name": "original_tool", "arguments": {}}}',
            $sessionId
        );

        // Verify the handler received the modified request
        $this->assertInstanceOf(CallToolRequest::class, $handlerReceivedRequest);

        $this->assertSame('modified_tool', $handlerReceivedRequest->name);
        $this->assertSame(['modified' => true], $handlerReceivedRequest->arguments);
    }

    #[TestDox('RequestEvent works with null EventDispatcher')]
    public function testRequestEventWithNullDispatcher(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['result' => 'success']));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: null,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should not crash - success
        $this->expectNotToPerformAssertions();
    }

    #[TestDox('ResponseEvent is dispatched when handler returns Response')]
    public function testResponseEventIsDispatched(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['result' => 'success']));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should have RequestEvent and ResponseEvent
        $this->assertCount(2, $capturedEvents);
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertInstanceOf(ResponseEvent::class, $capturedEvents[1]);

        /** @var ResponseEvent $responseEvent */
        $responseEvent = $capturedEvents[1];
        $this->assertSame($session, $responseEvent->getSession());
        $this->assertSame('ping', $responseEvent->getMethod());
        $this->assertInstanceOf(Response::class, $responseEvent->getResponse());
    }

    #[TestDox('ResponseEvent modification is used when sending')]
    public function testResponseEventModificationIsUsed(): void
    {
        $outgoingQueue = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) {
                if ($event instanceof ResponseEvent) {
                    // Simulate a listener modifying the response
                    $modifiedResponse = new Response(
                        $event->getResponse()->getId(),
                        ['result' => 'modified', 'original' => false]
                    );
                    $event->setResponse($modifiedResponse);
                }

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['result' => 'original']));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);
        $session
            ->method('set')
            ->willReturnCallback(static function ($key, $value) use (&$outgoingQueue) {
                if ('_mcp.outgoing_queue' === $key) {
                    $outgoingQueue = $value;
                }
            });

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Verify the MODIFIED response was queued
        $this->assertNotEmpty($outgoingQueue);
        $lastQueued = end($outgoingQueue);
        $this->assertIsArray($lastQueued);
        $this->assertArrayHasKey('message', $lastQueued);

        $decoded = json_decode($lastQueued['message'], true);
        $this->assertSame('modified', $decoded['result']['result']);
        $this->assertFalse($decoded['result']['original']);
    }

    #[TestDox('ErrorEvent is dispatched when handler returns Error')]
    public function testErrorEventIsDispatchedForErrorResult(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(Error::forInternalError('test error', 1));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should have RequestEvent and ErrorEvent
        $this->assertCount(2, $capturedEvents);
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertInstanceOf(ErrorEvent::class, $capturedEvents[1]);

        /** @var ErrorEvent $errorEvent */
        $errorEvent = $capturedEvents[1];
        $this->assertSame($session, $errorEvent->getSession());
        $this->assertNull($errorEvent->getThrowable());
        $this->assertInstanceOf(Error::class, $errorEvent->getError());
    }

    #[TestDox('ErrorEvent is dispatched on InvalidArgumentException')]
    public function testErrorEventIsDispatchedForInvalidArgument(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \InvalidArgumentException('Invalid param'));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should have RequestEvent and ErrorEvent
        $this->assertCount(2, $capturedEvents);
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertInstanceOf(ErrorEvent::class, $capturedEvents[1]);

        /** @var ErrorEvent $errorEvent */
        $errorEvent = $capturedEvents[1];
        $this->assertInstanceOf(\InvalidArgumentException::class, $errorEvent->getThrowable());
        $this->assertSame('Invalid param', $errorEvent->getThrowable()->getMessage());
    }

    #[TestDox('ErrorEvent is dispatched on generic Throwable')]
    public function testErrorEventIsDispatchedForGenericException(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \RuntimeException('Runtime error'));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Should have RequestEvent and ErrorEvent
        $this->assertCount(2, $capturedEvents);
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertInstanceOf(ErrorEvent::class, $capturedEvents[1]);

        /** @var ErrorEvent $errorEvent */
        $errorEvent = $capturedEvents[1];
        $this->assertInstanceOf(\RuntimeException::class, $errorEvent->getThrowable());
        $this->assertSame('Runtime error', $errorEvent->getThrowable()->getMessage());
    }

    #[TestDox('ErrorEvent is dispatched when no handler found')]
    public function testErrorEventIsDispatchedForMethodNotFound(): void
    {
        $capturedEvents = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvents) {
                $capturedEvents[] = $event;

                return $event;
            });

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('get')->willReturn([]);
        $session->expects($this->once())->method('save');
        $session->expects($this->atLeastOnce())->method('set');

        $this->sessionFactory->method('create')->willReturn($session);  // create() for initialize
        $this->sessionStore->method('exists')->willReturn(false);  // No existing session

        $protocol = new Protocol(
            requestHandlers: [], // No handlers
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "initialize", "id": 1, "params": {"protocolVersion": "2024-11-05", "capabilities": {}, "clientInfo": {"name": "test", "version": "1.0"}}}',
            null  // Initialize must not have sessionId
        );

        // Should have RequestEvent and ErrorEvent
        $this->assertCount(2, $capturedEvents);
        $this->assertInstanceOf(RequestEvent::class, $capturedEvents[0]);
        $this->assertInstanceOf(ErrorEvent::class, $capturedEvents[1]);

        /** @var ErrorEvent $errorEvent */
        $errorEvent = $capturedEvents[1];
        $this->assertNull($errorEvent->getThrowable());
        $this->assertInstanceOf(Error::class, $errorEvent->getError());
    }

    #[TestDox('NotificationEvent is dispatched when notification received')]
    public function testNotificationEventIsDispatched(): void
    {
        $capturedEvent = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;

                return $event instanceof NotificationEvent;
            }))
            ->willReturnArgument(0);

        $handler = $this->createMock(NotificationHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($this->once())->method('handle');

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );

        $this->assertNotNull($capturedEvent);
        $this->assertInstanceOf(NotificationEvent::class, $capturedEvent);
        $this->assertSame($session, $capturedEvent->getSession());
        $this->assertSame('notifications/initialized', $capturedEvent->getMethod());
    }

    #[TestDox('NotificationEvent modification is used by handlers')]
    public function testNotificationEventModificationIsUsed(): void
    {
        $handlerReceivedNotification = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) {
                if ($event instanceof NotificationEvent) {
                    // Simulate a listener modifying the notification
                    $modifiedNotification = LoggingMessageNotification::fromArray([
                        'jsonrpc' => '2.0',
                        'method' => 'notifications/message',
                        'params' => [
                            'level' => 'error',
                            'data' => 'modified message',
                            'logger' => 'modified_logger',
                        ],
                    ]);

                    $event->setNotification($modifiedNotification);
                }

                return $event;
            });

        $handler = $this->createMock(NotificationHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler
            ->method('handle')
            ->willReturnCallback(static function ($notification) use (&$handlerReceivedNotification) {
                $handlerReceivedNotification = $notification;
            });

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/message", "params": {"level": "info", "data": "original message"}}',
            $sessionId
        );

        // Verify the handler received the MODIFIED notification
        $this->assertInstanceOf(LoggingMessageNotification::class, $handlerReceivedNotification);
        $this->assertSame(LoggingLevel::Error, $handlerReceivedNotification->level);
        $this->assertSame('modified message', $handlerReceivedNotification->data);
        $this->assertSame('modified_logger', $handlerReceivedNotification->logger);
    }

    #[TestDox('NotificationEvent works with null EventDispatcher')]
    public function testNotificationEventWithNullDispatcher(): void
    {
        $handler = $this->createMock(NotificationHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($this->once())->method('handle');

        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
            eventDispatcher: null,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
    }
}
