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

use Mcp\Event\ClientResponseEvent;
use Mcp\Event\ErrorEvent;
use Mcp\Event\NotificationEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Event\ServerRequestEvent;
use Mcp\Exception\RuntimeException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Tests\Unit\Fixtures\PollingLoopTransport;
use Mcp\Tests\Unit\Fixtures\RecordingTransport;
use Mcp\Tests\Unit\Fixtures\ThrowingRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Uid\Uuid;

final class ProtocolTest extends TestCase
{
    private MockObject&SessionManagerInterface $sessionManager;
    /** @var MockObject&TransportInterface<mixed> */
    private MockObject&TransportInterface $transport;

    protected function setUp(): void
    {
        $this->sessionManager = $this->createMock(SessionManagerInterface::class);
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handlerA, $handlerB, $handlerC],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
    }

    #[TestDox('An id-less error response from the client is logged and ignored, not stored under a collapsed session key')]
    public function testIdLessErrorResponseIsIgnored(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects($this->never())->method('set');

        $this->sessionManager->method('exists')->willReturn(true);
        $this->sessionManager->method('createWithId')->willReturn($session);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "error": {"code": -32700, "message": "Parse error"}}',
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);
        $session->method('getId')->willReturn(Uuid::v4());

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handlerA, $handlerB, $handlerC],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );

        // Check that the response was sent
        $outgoing = $transport->sent;
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
            sessionManager: $this->sessionManager,
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
            sessionManager: $this->sessionManager,
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

                    // Echoing the id lets a probing client correlate the refusal.
                    return isset($decoded['error'])
                        && 1 === ($decoded['id'] ?? null)
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
            sessionManager: $this->sessionManager,
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
        $this->sessionManager->method('exists')->willReturn(false);

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
            sessionManager: $this->sessionManager,
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
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            'invalid json',
            null
        );
    }

    #[TestDox('Unrecoverable parse error does not fabricate an empty-string id')]
    public function testParseErrorDoesNotFabricateEmptyStringId(): void
    {
        $sentPayload = null;
        $this->transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function ($data) use (&$sentPayload) {
                $sentPayload = $data;
            });

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        // Well-formed JSON nested past PHP's json_decode() depth limit (512), mirroring
        // issue #333: json_decode() throws "Maximum stack depth exceeded" so the request
        // carries a real numeric id (900512) that cannot be recovered once decoding fails.
        $deeplyNested = str_repeat('[', 600).str_repeat(']', 600);
        $input = '{"jsonrpc":"2.0","id":900512,"method":"initialize","params":'.$deeplyNested.'}';

        $protocol->processInput($this->transport, $input, null);

        $this->assertNotNull($sentPayload);
        $decoded = json_decode($sentPayload, true);
        $this->assertSame(Error::PARSE_ERROR, $decoded['error']['code']);
        // The original id is genuinely unrecoverable after a parse failure: it must never be
        // fabricated as an empty string, and — per the MCP `RequestId` schema, which never
        // allows `null` — the key must be omitted rather than sent as `id: null`.
        $this->assertArrayNotHasKey('id', $decoded, 'Unrecoverable parse error must omit id, not fabricate one');
    }

    #[TestDox('Invalid message structure returns error')]
    public function testInvalidMessageStructureReturnsError(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "params": {}}',
            $sessionId
        );

        // Check that the error was sent
        $outgoing = $transport->sent;
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INVALID_REQUEST, $message['error']['code']);
    }

    #[TestDox('An unexpected throwable while creating a message returns an internal error under its id')]
    public function testUnexpectedThrowableWhileCreatingMessagesReturnsInternalError(): void
    {
        $sent = null;
        $this->transport->expects($this->once())
            ->method('send')
            ->willReturnCallback(static function ($data) use (&$sent) {
                $sent = $data;
            });

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: new MessageFactory([ThrowingRequest::class]),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "test/throwing"}',
            Uuid::v4()
        );

        $decoded = json_decode((string) $sent, true);
        $this->assertSame(Error::INTERNAL_ERROR, $decoded['error']['code']);
        $this->assertSame(1, $decoded['id'], 'The peer must be able to correlate the failure with its request.');
        $this->assertStringNotContainsString('must not leak', $decoded['error']['message']);
    }

    #[TestDox('A batch that fails to hydrate is answered once, under the empty id')]
    public function testBatchThatFailsToHydrateIsAnsweredOnce(): void
    {
        $sent = [];
        $this->transport->method('send')->willReturnCallback(static function ($data) use (&$sent) {
            $sent[] = $data;
        });

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: new MessageFactory([PingRequest::class, ThrowingRequest::class]),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '[{"jsonrpc": "2.0", "id": 1, "method": "ping"}, {"jsonrpc": "2.0", "id": 2, "method": "test/throwing"}]',
            Uuid::v4()
        );

        $this->assertCount(1, $sent);

        $decoded = json_decode($sent[0], true);
        $this->assertSame(Error::INTERNAL_ERROR, $decoded['error']['code']);
        $this->assertSame('', $decoded['id'], 'The failure cannot be attributed to one request of the batch.');
    }

    #[TestDox('A batch holding only notifications is not answered when processing fails')]
    public function testBatchOfNotificationsIsNotAnsweredWhenProcessingFails(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('save')->willThrowException(new \RuntimeException('storage is gone'));

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $this->transport->expects($this->never())->method('send');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '[{"jsonrpc": "2.0", "method": "notifications/initialized"}]',
            Uuid::v4()
        );
    }

    #[TestDox('An unexpected throwable while saving the session does not answer a notification')]
    public function testUnexpectedThrowableWhileSavingSessionDoesNotEscape(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('save')->willThrowException(new \RuntimeException('storage is gone'));

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        // JSON-RPC forbids answering a notification, so the failure is only logged.
        $this->transport->expects($this->never())->method('send');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            Uuid::v4()
        );
    }

    #[TestDox('A session that fails to save does not add an error to the responses already sent')]
    public function testSaveFailureKeepsSentResponses(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['status' => 'ok']));

        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn(Uuid::v4());
        $session->method('save')->willThrowException(new \RuntimeException('storage is gone'));

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "write_file", "arguments": {}}}',
            Uuid::v4()
        );

        // The tool ran: answering it with an internal error too would tell the client it did not.
        $this->assertCount(1, $transport->sent);
        $this->assertSame(['status' => 'ok'], json_decode($transport->sent[0]['message'], true)['result']);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideSaveFailures(): iterable
    {
        yield 'store throws' => [true];
        yield 'store reports false' => [false];
    }

    #[TestDox('A suspended handler is not handed to the transport when the session fails to save what it awaits: $_dataName')]
    #[DataProvider('provideSaveFailures')]
    public function testSaveFailureAbortsSuspendedRequest(bool $throws): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return new Response(1, []);
        });

        $store = new class extends InMemorySessionStore {
            public bool $failWrites = false;
            public bool $throws = true;

            public function write(Uuid $id, string $data): bool
            {
                if ($this->failWrites && $this->throws) {
                    throw new \RuntimeException('storage is gone');
                }

                return !$this->failWrites && parent::write($id, $data);
            }
        };
        $store->throws = $throws;
        $sessionManager = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $sessionManager->createWithId($sessionId)->save();
        $store->failWrites = true;

        // Resumed, the fiber would wait for a client response to a request the session never stored.
        $this->transport->expects($this->never())->method('attachFiberToSession');

        $sent = [];
        $this->transport->method('send')->willReturnCallback(static function (string $data) use (&$sent): void {
            $sent[] = json_decode($data, true);
        });

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "confirm", "arguments": {}}}',
            $sessionId
        );

        $this->assertCount(1, $sent);
        $this->assertSame(1, $sent[0]['id']);
        $this->assertSame(Error::INTERNAL_ERROR, $sent[0]['error']['code']);
    }

    #[TestDox('An aborted suspended request leaves nothing behind when a later save succeeds: $_dataName')]
    #[DataProvider('provideSaveFailures')]
    public function testAbortedSuspendedRequestIsRolledBack(bool $throws): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return new Response(1, []);
        });

        // Fails once, then recovers: the save at the end of processing goes through.
        $store = new class extends InMemorySessionStore {
            public int $failingWrites = 0;
            public bool $throws = true;

            public function write(Uuid $id, string $data): bool
            {
                if ($this->failingWrites > 0) {
                    --$this->failingWrites;
                    if ($this->throws) {
                        throw new \RuntimeException('storage hiccup');
                    }

                    return false;
                }

                return parent::write($id, $data);
            }
        };
        $store->throws = $throws;
        $sessionManager = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $sessionManager->createWithId($sessionId)->save();
        $store->failingWrites = 1;

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "confirm", "arguments": {}}}',
            $sessionId
        );

        // The client must not get a request for a tool call that was already answered with an error.
        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
        $this->assertSame([], $protocol->getPendingRequests($sessionId));
    }

    #[TestDox('A failing notification event listener does not produce a response')]
    public function testFailingNotificationListenerDoesNotProduceResponse(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $queue = [];
        $session->method('get')->willReturnCallback(static function ($key, $default = null) use (&$queue) {
            return '_mcp.outgoing_queue' === $key ? $queue : $default;
        });
        $session->method('set')->willReturnCallback(static function ($key, $value) use (&$queue) {
            if ('_mcp.outgoing_queue' === $key) {
                $queue = $value;
            }
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function ($event) {
            if ($event instanceof NotificationEvent) {
                throw new \RuntimeException('listener blew up');
            }

            return $event;
        });

        $this->transport->expects($this->never())->method('send');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $dispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );

        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
    }

    /**
     * @return iterable<string, array{string, string|int}>
     */
    public static function recoverableIdProvider(): iterable
    {
        yield 'positive int' => ['{"jsonrpc": "2.0", "id": 42, "params": {}}', 42];
        yield 'zero int (truthiness trap)' => ['{"jsonrpc": "2.0", "id": 0, "params": {}}', 0];
        yield 'string id' => ['{"jsonrpc": "2.0", "id": "req-1", "params": {}}', 'req-1'];
    }

    #[DataProvider('recoverableIdProvider')]
    #[TestDox('Invalid but parseable message preserves its recoverable id')]
    public function testInvalidMessagePreservesRecoverableId(string $input, string|int $expectedId): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        // Valid JSON carrying a real id but missing method/result/error: the message is
        // structurally invalid, yet its id IS recoverable from the decoded payload.
        $protocol->processInput(
            $transport,
            $input,
            $sessionId
        );

        $outgoing = $transport->sent;
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INVALID_REQUEST, $message['error']['code']);
        $this->assertSame($expectedId, $message['id'], 'Invalid-but-parseable message must preserve its recoverable id, not return ""');
    }

    #[TestDox('Request without handler returns method not found error')]
    public function testRequestWithoutHandlerReturnsMethodNotFoundError(): void
    {
        $session = $this->createMock(SessionInterface::class);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
            $sessionId
        );

        // Check that the error was sent
        $outgoing = $transport->sent;
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );

        // Check that the error was sent
        $outgoing = $transport->sent;
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "test"}}',
            $sessionId
        );

        // Check that the error was sent
        $outgoing = $transport->sent;
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('error', $message);
        $this->assertEquals(Error::INTERNAL_ERROR, $message['error']['code']);
        $this->assertSame('Internal server error.', $message['error']['message']);
        $this->assertStringNotContainsString('Unexpected error', $message['error']['message']);
    }

    #[TestDox('Failure while dispatching an outbound request is answered under the inbound request id')]
    public function testOutboundRequestFailureIsAnsweredUnderInboundRequestId(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            // Suspend with an outbound, id-less request, as sampling/elicitation handlers do.
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return new Response(1, []);
        });

        $exception = new \RuntimeException('Transport unavailable');
        $this->transport->method('attachFiberToSession')->willThrowException($exception);

        $sent = [];
        $this->transport->method('send')->willReturnCallback(static function (string $data) use (&$sent): void {
            $sent[] = json_decode($data, true);
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());

        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event;

            return $event;
        });

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $sessionId = $session->getId();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "ping"}',
            $sessionId
        );

        $errorEvents = array_values(array_filter($events, static fn (object $event): bool => $event instanceof ErrorEvent));
        $this->assertCount(1, $errorEvents);
        $this->assertSame($exception, $errorEvents[0]->getThrowable());
        $this->assertSame(1, $errorEvents[0]->getError()->getId());

        // The outbound request was queued for the client before the failure; the error is the response.
        $outgoing = array_map(static fn (array $outgoingMessage): array => json_decode($outgoingMessage['message'], true), $protocol->consumeOutgoingMessages($sessionId));
        $this->assertCount(1, $outgoing);
        $this->assertSame('ping', $outgoing[0]['method']);

        $this->assertCount(1, $sent);
        $this->assertSame(1, $sent[0]['id']);
        $this->assertSame(Error::INTERNAL_ERROR, $sent[0]['error']['code']);
    }

    #[TestDox('Concurrent streams on one session each poll only the client request their own fiber sent')]
    public function testConcurrentStreamsPollOnlyTheirOwnPendingRequest(): void
    {
        [$protocol, $sessionId, $firstStream, $secondStream] = $this->startTwoStreamsWaitingOnClient();

        $protocol->processInput($secondStream, '{"jsonrpc": "2.0", "id": 1001, "result": {}}', $sessionId);

        $this->assertSame([1000], $firstStream->getPendingRequestIds());
        $this->assertSame([1001], $secondStream->getPendingRequestIds());
    }

    #[TestDox('A client request a fiber sends after resuming is polled only by its own stream')]
    public function testRequestYieldedOnResumeIsPolledOnlyByItsOwnStream(): void
    {
        [, $sessionId, $firstStream, $secondStream] = $this->startTwoStreamsWaitingOnClient();

        $firstStream->yieldFromFiber(new RequestSuspension(new PingRequest(), $sessionId->toRfc4122(), 5));

        $this->assertSame([1002], $firstStream->getPendingRequestIds());
        $this->assertSame([1001], $secondStream->getPendingRequestIds());
    }

    #[TestDox('A stream whose fiber resumes and sends a notification no longer polls the request it was waiting on')]
    public function testNotificationYieldedOnResumeClearsTheAwaitedRequest(): void
    {
        [, $sessionId, $firstStream, $secondStream] = $this->startTwoStreamsWaitingOnClient();

        $firstStream->yieldFromFiber(new NotificationSuspension(new LoggingMessageNotification(LoggingLevel::Info, 'hello'), $sessionId->toRfc4122()));

        $this->assertSame([], $firstStream->getPendingRequestIds());
        $this->assertSame([1001], $secondStream->getPendingRequestIds());
    }

    #[TestDox('A stream whose fiber first suspends on a notification polls none of the session\'s pending requests')]
    public function testStreamSuspendedOnNotificationPollsNoPendingRequest(): void
    {
        [$protocol, $sessionId, , $secondStream] = $this->startTwoStreamsWaitingOnClient();

        $thirdStream = new PollingLoopTransport();
        $protocol->connect($thirdStream);
        $protocol->processInput($thirdStream, '{"jsonrpc": "2.0", "id": 3, "method": "ping"}', $sessionId);

        $this->assertSame([], $thirdStream->getPendingRequestIds());
        $this->assertSame([1001], $secondStream->getPendingRequestIds());
    }

    #[TestDox('A stream stops polling a client request once it timed out')]
    public function testStreamStopsPollingTimedOutRequest(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            // A timeout of 0 seconds has expired by the first poll.
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 0));

            return new Response($request->getId(), []);
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
        );

        $session = $sessionManager->create();
        $session->save();
        $sessionId = $session->getId();

        $stream = new PollingLoopTransport();
        $protocol->connect($stream);
        $protocol->processInput($stream, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $sessionId);

        $this->assertSame([1000], $stream->getPendingRequestIds());

        $this->assertInstanceOf(Error::class, $protocol->checkResponse(1000, $sessionId));

        $this->assertSame([], $stream->getPendingRequestIds());
    }

    /**
     * Two tool calls on one session, each suspended on a request to the client, as with elicitation.
     * A further call with ID 3 suspends on a notification instead.
     *
     * @return array{Protocol, Uuid, PollingLoopTransport, PollingLoopTransport}
     */
    private function startTwoStreamsWaitingOnClient(): array
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            $sessionId = $session->getId()->toRfc4122();
            \Fiber::suspend(3 === $request->getId()
                ? new NotificationSuspension(new LoggingMessageNotification(LoggingLevel::Info, 'hello'), $sessionId)
                : new RequestSuspension(new PingRequest(), $sessionId, 5));

            return new Response(1, []);
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
        );

        $session = $sessionManager->create();
        $session->save();
        $sessionId = $session->getId();

        $firstStream = new PollingLoopTransport();
        $protocol->connect($firstStream);
        $protocol->processInput($firstStream, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $sessionId);

        $secondStream = new PollingLoopTransport();
        $protocol->connect($secondStream);
        $protocol->processInput($secondStream, '{"jsonrpc": "2.0", "id": 2, "method": "ping"}', $sessionId);

        return [$protocol, $sessionId, $firstStream, $secondStream];
    }

    #[TestDox('Notification handler exceptions are caught and logged')]
    public function testNotificationHandlerExceptionsAreCaught(): void
    {
        $handler = $this->createMock(NotificationHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException(new \RuntimeException('Handler error'));

        $session = $this->createMock(SessionInterface::class);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );

        // Check that the response was sent
        $outgoing = $transport->sent;
        $this->assertCount(1, $outgoing);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertArrayHasKey('result', $message);
        $this->assertEquals(['status' => 'ok'], $message['result']);
    }

    #[TestDox('A response goes to the transport with its session, never through the session queue')]
    public function testResponseIsSentNotQueued(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['status' => 'ok']));

        $sessions = new SessionManager(new InMemorySessionStore(), gcProbability: 0);
        $sessionId = Uuid::v4();
        $sessions->createWithId($sessionId)->save();

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessions,
        );

        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/list"}',
            $sessionId
        );

        $this->assertCount(1, $transport->sent);
        $this->assertSame(['status' => 'ok'], json_decode($transport->sent[0]['message'], true)['result']);
        $this->assertSame('response', $transport->sent[0]['context']['type']);
        $this->assertEquals($sessionId, $transport->sent[0]['context']['session_id']);
        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())
            ->method('save');

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handlerA],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '[{"jsonrpc": "2.0", "method": "tools/list", "id": 1}, {"jsonrpc": "2.0", "method": "prompts/list", "id": 2}]',
            $sessionId
        );

        // Check that both responses were sent
        $outgoing = $transport->sent;
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $session->expects($this->once())->method('save');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->expects($this->once())
            ->method('destroy')
            ->with($sessionId);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $transport = new RecordingTransport();

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $transport,
            '{"jsonrpc": "2.0", "method": "ping", "id": 1}',
            $sessionId
        );

        // Verify the MODIFIED response was sent
        $this->assertCount(1, $transport->sent);

        $decoded = json_decode($transport->sent[0]['message'], true);
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('create')->willReturn($session);  // create() for initialize
        $this->sessionManager->method('exists')->willReturn(false);  // No existing session

        $protocol = new Protocol(
            requestHandlers: [], // No handlers
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
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

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [$handler],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: null,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "method": "notifications/initialized"}',
            $sessionId
        );
    }

    #[TestDox('ServerRequestEvent is dispatched when server sends a request to the client')]
    public function testServerRequestEventIsDispatched(): void
    {
        $capturedEvent = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;

                return $event instanceof ServerRequestEvent;
            }))
            ->willReturnArgument(0);

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(static function ($key, $default = null) {
            if ('_mcp.request_id_counter' === $key) {
                return 1000;
            }

            return $default;
        });
        $session->method('getId')->willReturn(Uuid::v4());

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $request = PingRequest::fromArray([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'ping',
        ]);

        $protocol->sendRequest($request, 60, $session);

        $this->assertInstanceOf(ServerRequestEvent::class, $capturedEvent);
        $this->assertSame($session, $capturedEvent->getSession());
        $this->assertSame(60, $capturedEvent->getTimeout());
        $this->assertSame('ping', $capturedEvent->getMethod());
        $this->assertSame(1000, $capturedEvent->getRequest()->getId());
    }

    #[TestDox('ClientResponseEvent is dispatched when a client response is received')]
    public function testClientResponseEventIsDispatched(): void
    {
        $capturedEvent = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function ($event) use (&$capturedEvent) {
                $capturedEvent = $event;

                return $event instanceof ClientResponseEvent;
            }))
            ->willReturnArgument(0);

        $session = $this->createSessionWithPendingRequests([1000]);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1000, "result": {"action": "accept"}}',
            $sessionId
        );

        $this->assertInstanceOf(ClientResponseEvent::class, $capturedEvent);
        $this->assertSame($session, $capturedEvent->getSession());
        $this->assertSame(1000, $capturedEvent->getId());
        $this->assertFalse($capturedEvent->isError());
    }

    #[TestDox('ClientResponseEvent reports errors via isError()')]
    public function testClientResponseEventIsError(): void
    {
        $capturedEvent = null;

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$capturedEvent) {
                if ($event instanceof ClientResponseEvent) {
                    $capturedEvent = $event;
                }

                return $event;
            });

        $session = $this->createSessionWithPendingRequests([1000]);

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $sessionId = Uuid::v4();
        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 1000, "error": {"code": -32603, "message": "Client error"}}',
            $sessionId
        );

        $this->assertInstanceOf(ClientResponseEvent::class, $capturedEvent);
        $this->assertTrue($capturedEvent->isError());
        $this->assertSame(1000, $capturedEvent->getId());
    }

    #[TestDox('A response to an unknown request ID is neither dispatched nor stored')]
    public function testClientResponseEventIsNotDispatchedForUnknownId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $session = $this->createSessionWithPendingRequests([1000]);
        $session->expects($this->never())->method('set');

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $protocol->processInput(
            $this->transport,
            '{"jsonrpc": "2.0", "id": 4711, "result": {"action": "accept"}}',
            Uuid::v4()
        );
    }

    #[TestDox('A response with a string ID does not match the integer ID of a pending request')]
    public function testClientResponseEventIsNotDispatchedForNumericStringId(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->never())->method('dispatch');

        $session = $this->createSessionWithPendingRequests([1000]);
        $session->expects($this->never())->method('set');

        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            eventDispatcher: $eventDispatcher,
        );

        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": "1000", "result": {}}', Uuid::v4());
    }

    #[TestDox('A late response to a timed out request is neither dispatched nor stored')]
    public function testLateResponseToTimedOutRequestIsDropped(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->set('_mcp.pending_requests', [1000 => ['request_id' => 1000, 'timeout' => 5, 'timestamp' => time() - 10]]);
        $session->save();

        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1000, "result": {}}', $session->getId());

        $this->assertNull($sessionManager->createWithId($session->getId())->get('_mcp.responses.1000'));
    }

    #[TestDox('A timed out client request is reported as an error and its pending entry is dropped')]
    public function testCheckResponseReportsTimedOutRequestAndDropsItsPendingEntry(): void
    {
        [$protocol, $sessionManager, $session] = $this->createProtocolWithPendingRequests([
            1000 => ['request_id' => 1000, 'timeout' => 5, 'timestamp' => time() - 10],
            1001 => ['request_id' => 1001, 'timeout' => 120, 'timestamp' => time()],
        ]);

        $result = $protocol->checkResponse(1000, $session->getId());

        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(1000, $result->getId());
        $this->assertSame(Error::INTERNAL_ERROR, $result->code);
        $this->assertSame('Request timed out', $result->message);
        $this->assertSame([1001], array_keys($protocol->getPendingRequests($session->getId())));
        $this->assertSame([1001], array_keys($sessionManager->createWithId($session->getId())->get('_mcp.pending_requests')));

        $this->assertNull($protocol->checkResponse(1000, $session->getId()), 'The timeout is reported once.');
    }

    #[TestDox('A client request within its timeout stays pending')]
    public function testCheckResponseKeepsRequestWithinItsTimeout(): void
    {
        $pending = [1000 => ['request_id' => 1000, 'timeout' => 120, 'timestamp' => time() - 10]];
        [$protocol, , $session] = $this->createProtocolWithPendingRequests($pending);

        $this->assertNull($protocol->checkResponse(1000, $session->getId()));
        $this->assertSame($pending, $protocol->getPendingRequests($session->getId()));
    }

    #[TestDox('An answer that arrived before the timeout was noticed wins over the timeout')]
    public function testCheckResponseReturnsAnswerOfExpiredRequest(): void
    {
        [$protocol, , $session] = $this->createProtocolWithPendingRequests([
            1000 => ['request_id' => 1000, 'timeout' => 5, 'timestamp' => time() - 10],
        ]);
        $session->set('_mcp.responses.1000', ['jsonrpc' => '2.0', 'id' => 1000, 'result' => ['action' => 'accept']]);
        $session->save();

        $result = $protocol->checkResponse(1000, $session->getId());

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(1000, $result->getId());
        $this->assertSame([], $protocol->getPendingRequests($session->getId()));
    }

    #[TestDox('An answer arriving after the timeout was reported is dropped')]
    public function testAnswerAfterReportedTimeoutIsDropped(): void
    {
        [$protocol, $sessionManager, $session] = $this->createProtocolWithPendingRequests([
            1000 => ['request_id' => 1000, 'timeout' => 5, 'timestamp' => time() - 10],
        ]);

        $this->assertInstanceOf(Error::class, $protocol->checkResponse(1000, $session->getId()));

        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1000, "result": {}}', $session->getId());

        $this->assertNull($sessionManager->createWithId($session->getId())->get('_mcp.responses.1000'));
        $this->assertNull($protocol->checkResponse(1000, $session->getId()));
    }

    #[TestDox('ClientResponseEvent::getId() is null when the error has no id')]
    public function testClientResponseEventGetIdCanBeNull(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $event = new ClientResponseEvent(Error::forParseError('bad json'), $session);

        $this->assertNull($event->getId());
        $this->assertTrue($event->isError());
    }

    #[TestDox('A suspended fiber dispatches its result under its own request once it completes')]
    public function testSuspendedFibersDispatchResultUnderTheirOwnRequest(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response|Error {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return 2 === $request->getId()
                ? Error::forInternalError('failed', 2)
                : new Response($request->getId(), []);
        });

        $fibers = [];
        $this->transport->method('attachFiberToSession')->willReturnCallback(static function (\Fiber $fiber) use (&$fibers): void {
            $fibers[] = $fiber;
        });

        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event;

            return $event;
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $session->getId());
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 2, "method": "ping"}', $session->getId());

        $this->assertCount(2, $fibers);
        $this->assertCount(0, array_filter($events, static fn (object $event): bool => $event instanceof ResponseEvent || $event instanceof ErrorEvent));

        $fibers[1]->resume();
        $fibers[0]->resume();

        $results = array_values(array_filter($events, static fn (object $event): bool => $event instanceof ResponseEvent || $event instanceof ErrorEvent));
        $this->assertCount(2, $results);
        $this->assertInstanceOf(ErrorEvent::class, $results[0]);
        $this->assertSame(2, $results[0]->getRequest()->getId());
        $this->assertInstanceOf(ResponseEvent::class, $results[1]);
        $this->assertSame(1, $results[1]->getRequest()->getId());
    }

    #[TestDox('A throwing result listener of a resumed fiber is answered with an internal error')]
    public function testResumedFiberTurnsThrowingResponseListenerIntoInternalError(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return new Response($request->getId(), []);
        });

        $fiber = null;
        $this->transport->method('attachFiberToSession')->willReturnCallback(static function (\Fiber $attached) use (&$fiber): void {
            $fiber = $attached;
        });

        $exception = new RuntimeException('listener blew up');
        $errorEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use ($exception, &$errorEvents): object {
            if ($event instanceof ResponseEvent) {
                throw $exception;
            }
            if ($event instanceof ErrorEvent) {
                $errorEvents[] = $event;
            }

            return $event;
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $session->getId());

        $this->assertInstanceOf(\Fiber::class, $fiber);
        $fiber->resume();

        $this->assertTrue($fiber->isTerminated());
        $result = $fiber->getReturn();
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(Error::INTERNAL_ERROR, $result->code);
        $this->assertSame(1, $result->getId());

        $this->assertCount(1, $errorEvents);
        $this->assertSame($exception, $errorEvents[0]->getThrowable());
        $this->assertSame(1, $errorEvents[0]->getRequest()->getId());
    }

    #[TestDox('A throwing error listener of a resumed fiber still leaves an internal error')]
    public function testResumedFiberSurvivesThrowingErrorListener(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Error {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return Error::forInvalidParams('bad input', $request->getId());
        });

        $fiber = null;
        $this->transport->method('attachFiberToSession')->willReturnCallback(static function (\Fiber $attached) use (&$fiber): void {
            $fiber = $attached;
        });

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof ErrorEvent) {
                throw new RuntimeException('error listener blew up');
            }

            return $event;
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $session->getId());

        $this->assertInstanceOf(\Fiber::class, $fiber);
        $fiber->resume();

        $this->assertTrue($fiber->isTerminated());
        $result = $fiber->getReturn();
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(Error::INTERNAL_ERROR, $result->code);
        $this->assertSame(1, $result->getId());
    }

    #[TestDox('A handler throwing after its fiber resumed is answered with an internal error')]
    public function testResumedFiberTurnsThrowingHandlerIntoInternalError(): void
    {
        $exception = new RuntimeException('handler blew up');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session) use ($exception): Response {
            \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            throw $exception;
        });

        $fiber = null;
        $this->transport->method('attachFiberToSession')->willReturnCallback(static function (\Fiber $attached) use (&$fiber): void {
            $fiber = $attached;
        });

        $events = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event;

            return $event;
        });

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": 1, "method": "ping"}', $session->getId());

        $this->assertInstanceOf(\Fiber::class, $fiber);
        $fiber->resume();

        $this->assertTrue($fiber->isTerminated());
        $result = $fiber->getReturn();
        $this->assertInstanceOf(Error::class, $result);
        $this->assertSame(Error::INTERNAL_ERROR, $result->code);
        $this->assertSame(1, $result->getId());

        $errorEvents = array_values(array_filter($events, static fn (object $event): bool => $event instanceof ErrorEvent));
        $this->assertCount(1, $errorEvents);
        $this->assertSame($exception, $errorEvents[0]->getThrowable());
        $this->assertCount(0, array_filter($events, static fn (object $event): bool => $event instanceof ResponseEvent));
    }

    #[TestDox('ClientResponseEvent is not dispatched for an id-less error from the client')]
    public function testClientResponseEventIsNotDispatchedForIdLessError(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
            eventDispatcher: $dispatcher,
        );

        $session = $sessionManager->create();
        $session->save();
        $protocol->processInput($this->transport, '{"jsonrpc": "2.0", "id": null, "error": {"code": -32700, "message": "Parse error"}}', $session->getId());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMessagesCarryingPayloads(): iterable
    {
        yield 'tools/call request' => [
            '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "login", "arguments": {"password": "s3cr3t-payload"}}}',
            'tools/call',
        ];
        yield 'client response to an elicitation' => [
            '{"jsonrpc": "2.0", "id": 1000, "result": {"action": "accept", "content": {"password": "s3cr3t-payload"}}}',
            '1000',
        ];
        yield 'notification' => [
            '{"jsonrpc": "2.0", "method": "notifications/cancelled", "params": {"requestId": 1, "reason": "s3cr3t-payload"}}',
            'notifications/cancelled',
        ];
    }

    #[TestDox('Message payloads are only logged at debug level, info and above carry the method and id')]
    #[DataProvider('provideMessagesCarryingPayloads')]
    public function testMessagePayloadsAreOnlyLoggedAtDebugLevel(string $input, string $identifier): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(new Response(1, ['content' => []]));

        $session = $this->createMock(SessionInterface::class);
        $this->sessionManager->method('createWithId')->willReturn($session);
        $this->sessionManager->method('exists')->willReturn(true);

        $logger = new LevelRecordingLogger();
        $protocol = new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
            logger: $logger,
        );

        $protocol->processInput($this->transport, $input, Uuid::v4());

        $debug = $logger->contextsAt([LogLevel::DEBUG]);
        $infoAndAbove = $logger->contextsAt([LogLevel::INFO, LogLevel::NOTICE, LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY]);

        $this->assertStringNotContainsString('s3cr3t-payload', $infoAndAbove);
        $this->assertStringContainsString($identifier, $infoAndAbove);
        $this->assertStringContainsString('s3cr3t-payload', $debug);
    }

    #[TestDox('A notification suspension from the gateway round-trips into the outgoing queue')]
    public function testFiberYieldedNotificationSuspensionIsQueued(): void
    {
        $sessionId = Uuid::v4();
        $session = new Session(new InMemorySessionStore(), $sessionId);

        $this->sessionManager->method('createWithId')->willReturn($session);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $gateway = new ClientGateway($session);
        $notification = new LoggingMessageNotification(LoggingLevel::Info, 'hello');

        $fiber = new \Fiber(static fn () => $gateway->notify($notification));
        $suspension = $fiber->start();

        $this->assertInstanceOf(NotificationSuspension::class, $suspension);
        $this->assertSame($sessionId->toRfc4122(), $suspension->sessionId);

        $protocol->handleFiberYield($suspension, $sessionId);

        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);
        $this->assertSame(['type' => 'notification'], $outgoing[0]['context']);
        $this->assertSame(json_encode($notification), $outgoing[0]['message']);
    }

    #[TestDox('A request suspension from the gateway round-trips into the outgoing queue and pending requests')]
    public function testFiberYieldedRequestSuspensionIsQueued(): void
    {
        $sessionId = Uuid::v4();
        $session = new Session(new InMemorySessionStore(), $sessionId);

        $this->sessionManager->method('createWithId')->willReturn($session);

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        $gateway = new ClientGateway($session);

        $fiber = new \Fiber(static fn () => $gateway->listRoots(timeout: 45));
        $suspension = $fiber->start();

        $this->assertInstanceOf(RequestSuspension::class, $suspension);
        $this->assertSame($sessionId->toRfc4122(), $suspension->sessionId);
        $this->assertSame(45, $suspension->timeout);
        $this->assertNull($suspension->inputKey);

        $protocol->handleFiberYield($suspension, $sessionId);

        $pending = $protocol->getPendingRequests($sessionId);
        $this->assertCount(1, $pending);
        $this->assertSame(45, $pending[1000]['timeout']);

        $outgoing = $protocol->consumeOutgoingMessages($sessionId);
        $this->assertCount(1, $outgoing);
        $this->assertSame(['type' => 'request'], $outgoing[0]['context']);

        $message = json_decode($outgoing[0]['message'], true);
        $this->assertSame('roots/list', $message['method']);
        $this->assertSame(1000, $message['id']);
    }

    #[TestDox('A fiber yield that is not a suspension object is dropped without touching the session')]
    public function testFiberYieldedUnexpectedPayloadIsIgnored(): void
    {
        $this->sessionManager->expects($this->never())->method('createWithId');

        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $this->sessionManager,
        );

        // The pre-VO array shape is deliberately no longer accepted.
        // @phpstan-ignore argument.type
        $protocol->handleFiberYield(['type' => 'notification'], Uuid::v4());
    }

    /**
     * @param array<int, array{request_id: int, timeout: int, timestamp: int}> $pending
     *
     * @return array{Protocol, SessionManager, SessionInterface}
     */
    private function createProtocolWithPendingRequests(array $pending): array
    {
        $sessionManager = new SessionManager(new InMemorySessionStore());
        $protocol = new Protocol(
            requestHandlers: [],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: $sessionManager,
        );

        $session = $sessionManager->create();
        $session->set('_mcp.pending_requests', $pending);
        $session->save();

        return [$protocol, $sessionManager, $session];
    }

    /**
     * @param list<int> $requestIds
     */
    private function createSessionWithPendingRequests(array $requestIds): SessionInterface&MockObject
    {
        $pending = [];
        foreach ($requestIds as $requestId) {
            $pending[$requestId] = ['request_id' => $requestId, 'timeout' => 120, 'timestamp' => time()];
        }

        $session = $this->createMock(SessionInterface::class);
        $session->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => '_mcp.pending_requests' === $key ? $pending : $default
        );

        return $session;
    }
}

/**
 * Records every log entry with its level, so a test can tell what a logger
 * configured at a given minimum level would have written.
 */
final class LevelRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, context: array<string, mixed>}> */
    private array $records = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'context' => $context];
    }

    /**
     * The JSON-encoded contexts of every record logged at one of the given levels.
     *
     * @param list<string> $levels
     */
    public function contextsAt(array $levels): string
    {
        $contexts = [];
        foreach ($this->records as $record) {
            if (\in_array($record['level'], $levels, true)) {
                $contexts[] = $record['context'];
            }
        }

        return json_encode($contexts, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
