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

use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
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

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['result']);
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [$handlerA, $handlerB, $handlerC],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );
    }

    #[TestDox('Initialize request must not have a session ID')]
    public function testInitializeRequestWithSessionIdReturnsError(): void
    {
        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
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
                $this->callback(function ($data) {
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

        $protocol->connect($this->transport);

        $protocol->processInput(
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
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'session id is REQUIRED');
                }),
                $this->callback(function ($context) {
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

        $protocol->connect($this->transport);

        $protocol->processInput(
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
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && str_contains($decoded['error']['message'], 'Session not found or has expired');
                }),
                $this->callback(function ($context) {
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
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
                $this->callback(function ($data) {
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

        $protocol->connect($this->transport);

        $protocol->processInput(
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

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && Error::INVALID_REQUEST === $decoded['error']['code'];
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '{"jsonrpc": "2.0", "params": {}}',
            $sessionId
        );
    }

    #[TestDox('Request without handler returns method not found error')]
    public function testRequestWithoutHandlerReturnsMethodNotFoundError(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && Error::METHOD_NOT_FOUND === $decoded['error']['code']
                        && str_contains($decoded['error']['message'], 'No handler found');
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
            $sessionId
        );
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

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && Error::INVALID_PARAMS === $decoded['error']['code']
                        && str_contains($decoded['error']['message'], 'Invalid parameter');
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );
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

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['error'])
                        && Error::INTERNAL_ERROR === $decoded['error']['code']
                        && str_contains($decoded['error']['message'], 'Unexpected error');
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
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

        $this->transport->expects($this->once())
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['result']);
                }),
                $this->callback(function ($context) use ($sessionId) {
                    return isset($context['session_id']) && $context['session_id']->equals($sessionId);
                })
            );

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->connect($this->transport);

        $protocol->processInput(
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );
    }

    #[TestDox('Batch requests are processed and send multiple responses')]
    public function testBatchRequestsAreProcessed(): void
    {
        $handlerA = $this->createMock(RequestHandlerInterface::class);
        $handlerA->method('supports')->willReturn(true);
        $handlerA->method('handle')->willReturnCallback(function ($request) {
            return new Response($request->getId(), ['method' => $request::getMethod()]);
        });

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());

        $this->sessionFactory->method('createWithId')->willReturn($session);
        $this->sessionStore->method('exists')->willReturn(true);

        // Expect two calls to send()
        $this->transport->expects($this->exactly(2))
            ->method('send')
            ->with(
                $this->callback(function ($data) {
                    $decoded = json_decode($data, true);

                    return isset($decoded['result']);
                }),
                $this->anything()
            );

        $protocol = new Protocol(
            requestHandlers: [$handlerA],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionFactory: $this->sessionFactory,
            sessionStore: $this->sessionStore,
        );

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
            '[{"jsonrpc": "2.0", "method": "tools/list", "id": 1}, {"jsonrpc": "2.0", "method": "prompts/list", "id": 2}]',
            $sessionId
        );
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

        $protocol->connect($this->transport);

        $sessionId = Uuid::v4();
        $protocol->processInput(
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
}
