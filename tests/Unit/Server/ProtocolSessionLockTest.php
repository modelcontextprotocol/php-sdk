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

use Mcp\Exception\SessionLockException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\Handler\Request\PingHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Tests\Unit\Fixtures\RecordingTransport;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingLogger;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingSessionLock;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingSessionStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Where the protocol holds a configured session lock: from before it reads a session until it saved it.
 */
final class ProtocolSessionLockTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    private RecordingSessionStore $store;

    protected function setUp(): void
    {
        $this->store = new RecordingSessionStore($this->log);
    }

    #[TestDox('a request holds the lock from before the session is read until after it is saved')]
    public function testRequestHoldsTheLockAroundReadAndSave(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame([
            "acquire $sessionId",
            "read $sessionId",
            // save() reads again to write back only the keys this request changed
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
        $this->assertSame([], $lock->heldSessions());
        $this->assertCount(1, $transport->sent);
        $this->assertSame(1, json_decode($transport->sent[0]['message'], true)['id']);
    }

    #[TestDox('the lock is released when resolving the session throws')]
    public function testLockIsReleasedWhenTheSessionCannotBeResolved(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        // Found before the lock is taken, the store fails when the session is looked up again under it.
        $lookups = 0;
        $sessionManager->method('exists')->willReturnCallback(static function () use (&$lookups): bool {
            if (++$lookups > 1) {
                throw new \RuntimeException('store down');
            }

            return true;
        });
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), $sessionManager, sessionLock: $lock);
        $sessionId = Uuid::v4();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame(["acquire $sessionId", "release $sessionId"], $this->log);
        $this->assertSame([], $lock->heldSessions());
        $this->assertCount(1, $transport->sent);
        $this->assertSame(Error::INTERNAL_ERROR, json_decode($transport->sent[0]['message'], true)['error']['code']);
    }

    #[TestDox('a request for an unknown session takes no lock, so no lock is left behind for an id nobody uses')]
    public function testUnknownSessionTakesNoLock(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', Uuid::v4());

        $this->assertSame([], $this->log);
        $this->assertCount(1, $transport->sent);
        $this->assertSame(404, $transport->sent[0]['context']['status_code']);
    }

    #[TestDox('a session that ends while a request waits for its lock is refused under the lock')]
    public function testSessionEndedWhileWaitingForTheLockIsRefused(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        $sessionManager->method('exists')->willReturnOnConsecutiveCalls(true, false);
        $sessionManager->expects($this->never())->method('createWithId');
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), $sessionManager, sessionLock: $lock);
        $sessionId = Uuid::v4();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame(["acquire $sessionId", "release $sessionId"], $this->log);
        $this->assertCount(1, $transport->sent);
        $this->assertSame(404, $transport->sent[0]['context']['status_code']);
    }

    #[TestDox('a request without a session id, like initialize, takes no lock')]
    public function testRequestWithoutSessionIdTakesNoLock(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', null);

        $this->assertSame([], $this->log);
        $this->assertCount(1, $transport->sent);
        $this->assertSame(400, $transport->sent[0]['context']['status_code']);
    }

    #[TestDox('a request whose session is locked by another request is refused before the session is read')]
    public function testBusySessionIsRefusedWithoutReadingIt(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame([], $this->log);
        $this->assertCount(1, $transport->sent);
        $this->assertSame(503, $transport->sent[0]['context']['status_code']);

        $error = json_decode($transport->sent[0]['message'], true);
        $this->assertSame(1, $error['id']);
        $this->assertSame(Error::SERVER_ERROR, $error['error']['code']);
        $this->assertSame('Another request of this session is still being processed.', $error['error']['message']);
    }

    #[TestDox('a busy session is logged as a warning, not as an error')]
    public function testBusySessionIsLoggedAsAWarning(): void
    {
        $logger = new RecordingLogger();
        $protocol = $this->protocol(new RecordingSessionLock($this->log, acquirable: false), $logger);

        $protocol->processInput(new RecordingTransport(), '{"jsonrpc":"2.0","id":1,"method":"ping"}', $this->existingSession());

        $this->assertCount(1, $logger->messagesAt(LogLevel::WARNING));
        $this->assertStringContainsString('Timed out', $logger->messagesAt(LogLevel::WARNING)[0]);
        $this->assertSame([], $logger->messagesAt(LogLevel::ERROR));
    }

    #[TestDox('a lock backend that is down is answered with 503 saying so, and logged as an error with its cause')]
    public function testUnavailableLockIsRefusedAndLoggedWithItsCause(): void
    {
        $logger = new RecordingLogger();
        $protocol = $this->protocol(new RecordingSessionLock($this->log, failure: 'Connection refused'), $logger);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame([], $this->log);
        $this->assertCount(1, $transport->sent);
        $this->assertSame(503, $transport->sent[0]['context']['status_code']);

        $error = json_decode($transport->sent[0]['message'], true);
        $this->assertSame(1, $error['id']);
        $this->assertSame(Error::SERVER_ERROR, $error['error']['code']);
        $this->assertSame('The session lock is unavailable.', $error['error']['message']);

        $errors = $logger->messagesAt(LogLevel::ERROR);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Connection refused', $errors[0]);
        $this->assertSame([], $logger->messagesAt(LogLevel::WARNING));
    }

    #[TestDox('a stream polling while the lock backend is down gets nothing yet, instead of an exception')]
    public function testPollingWithAnUnavailableLockReturnsNothing(): void
    {
        $protocol = $this->protocol(new RecordingSessionLock($this->log, failure: 'Connection refused'));
        $sessionId = $this->existingSession();

        $this->assertNull($protocol->checkResponse(Uuid::v4(), $sessionId));
        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
        $this->assertSame([], $this->log);
    }

    #[TestDox('a session the client ended is destroyed even when the lock backend is down')]
    public function testDestroyingWithAnUnavailableLockStillDestroys(): void
    {
        $protocol = $this->protocol(new RecordingSessionLock($this->log, failure: 'Connection refused'));
        $sessionId = $this->existingSession();

        $protocol->destroySession($sessionId);

        $this->assertSame(["destroy $sessionId"], $this->log);
        $this->assertFalse($this->store->exists($sessionId));
    }

    #[TestDox('a refused batch is answered under the empty id, as a batch failure is')]
    public function testRefusedBatchIsAnsweredUnderTheEmptyId(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]', $sessionId);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(503, $transport->sent[0]['context']['status_code']);
        $this->assertSame('', json_decode($transport->sent[0]['message'], true)['id']);
    }

    #[TestDox('a client response refused for a busy session is not answered under its id, which is the client\'s own request id')]
    public function testRefusedClientResponseIsNotAnsweredUnderItsId(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1000,"result":{"action":"accept"}}', $sessionId);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(503, $transport->sent[0]['context']['status_code']);
        $error = json_decode($transport->sent[0]['message'], true);
        $this->assertNull($error['id']);
        $this->assertSame(Error::SERVER_ERROR, $error['error']['code']);
    }

    #[TestDox('a refused batch of client responses is not answered under the empty id either')]
    public function testRefusedBatchOfClientResponsesIsNotAnsweredUnderTheEmptyId(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '[{"jsonrpc":"2.0","id":1000,"result":{}},{"jsonrpc":"2.0","method":"notifications/initialized"}]', $sessionId);

        $this->assertCount(1, $transport->sent);
        $this->assertNull(json_decode($transport->sent[0]['message'], true)['id']);
    }

    #[TestDox('a client response whose processing fails is not answered at all, like a notification')]
    public function testFailingClientResponseIsNotAnswered(): void
    {
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        $sessionManager->method('exists')->willThrowException(new \RuntimeException('store down'));
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), $sessionManager, sessionLock: new RecordingSessionLock($this->log));
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1000,"result":{}}', Uuid::v4());

        $this->assertSame([], $transport->sent);
    }

    #[TestDox('consuming the outgoing queue locks around its read and save, without waiting for the lock')]
    public function testConsumingOutgoingMessagesLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession([
            '_mcp.outgoing_queue' => [['message' => '{"jsonrpc":"2.0","method":"notifications/message"}', 'context' => ['type' => 'notification']]],
        ]);

        $messages = $protocol->consumeOutgoingMessages($sessionId);

        $this->assertCount(1, $messages);
        $this->assertSame([
            "try-acquire $sessionId",
            "read $sessionId",
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
    }

    #[TestDox('consuming an empty outgoing queue still happens under the lock, and does not save')]
    public function testConsumingAnEmptyQueueLocksWithoutSaving(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
        $this->assertSame(["try-acquire $sessionId", "read $sessionId", "release $sessionId"], $this->log);
    }

    #[TestDox('consuming a client response locks around its read and save, without waiting for the lock')]
    public function testCheckingForAResponseLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $requestId = Uuid::v4();
        $id = $requestId->toRfc4122();
        $sessionId = $this->existingSession([
            '_mcp.pending_requests' => [$id => ['request_id' => $id, 'timeout' => 120, 'timestamp' => time()]],
            '_mcp.responses' => [$id => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['ok' => true]]],
        ]);

        $this->assertInstanceOf(Response::class, $protocol->checkResponse($requestId, $sessionId));
        $this->assertSame([
            "try-acquire $sessionId",
            "read $sessionId",
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
    }

    #[TestDox('reading the pending requests takes no lock')]
    public function testReadingPendingRequestsTakesNoLock(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession([
            '_mcp.pending_requests' => [7 => ['request_id' => 7, 'timeout' => 120, 'timestamp' => time()]],
        ]);

        $this->assertArrayHasKey(7, $protocol->getPendingRequests($sessionId));
        $this->assertSame(["read $sessionId"], $this->log);
    }

    #[TestDox('a poll that cannot get the lock returns nothing and leaves the session alone, to try again next turn')]
    public function testPollingABusySessionLeavesItAlone(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $requestId = Uuid::v4();
        $id = $requestId->toRfc4122();
        $sessionId = $this->existingSession([
            '_mcp.outgoing_queue' => [['message' => '{"jsonrpc":"2.0","method":"notifications/message"}', 'context' => ['type' => 'notification']]],
            '_mcp.pending_requests' => [$id => ['request_id' => $id, 'timeout' => 120, 'timestamp' => time()]],
            '_mcp.responses' => [$id => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['ok' => true]]],
        ]);

        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
        $this->assertNull($protocol->checkResponse($requestId, $sessionId));
        $this->assertSame([], $this->log);

        $session = new Session($this->store, $sessionId);
        $this->assertCount(1, $session->get('_mcp.outgoing_queue'));
        $this->assertArrayHasKey($id, $session->get('_mcp.responses'));
    }

    #[TestDox('storing the request a resumed fiber sends locks around its read and save')]
    public function testFiberYieldLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $requestId = $protocol->handleFiberYield(new RequestSuspension(new PingRequest(), $sessionId->toRfc4122(), 5), $sessionId);

        $this->assertInstanceOf(Uuid::class, $requestId);
        $this->assertSame([
            "acquire $sessionId",
            "read $sessionId",
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
        $this->assertArrayHasKey($requestId->toRfc4122(), (new Session($this->store, $sessionId))->get('_mcp.pending_requests'));
    }

    #[TestDox('a resumed fiber whose session is locked by another request fails loudly: its request must not be lost silently')]
    public function testFiberYieldOnABusySessionThrows(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $this->expectException(SessionLockException::class);

        $protocol->handleFiberYield(new RequestSuspension(new PingRequest(), $sessionId->toRfc4122(), 5), $sessionId);
    }

    #[TestDox('destroying a session takes its lock, so a request still running cannot write it back')]
    public function testDestroyingASessionLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $protocol->destroySession($sessionId);

        $this->assertSame(["acquire $sessionId", "destroy $sessionId", "release $sessionId"], $this->log);
        $this->assertFalse($this->store->exists($sessionId));
    }

    #[TestDox('a session the client ended is destroyed even when its lock is busy')]
    public function testDestroyingABusySessionStillDestroysIt(): void
    {
        $lock = new RecordingSessionLock($this->log, acquirable: false);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $protocol->destroySession($sessionId);

        $this->assertSame(["destroy $sessionId"], $this->log);
        $this->assertFalse($this->store->exists($sessionId));
    }

    #[TestDox('ending an unknown session takes no lock')]
    public function testDestroyingAnUnknownSessionTakesNoLock(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = Uuid::v4();

        $protocol->destroySession($sessionId);

        $this->assertSame(["destroy $sessionId"], $this->log);
    }

    #[TestDox('without a lock configured, nothing but the store is touched')]
    public function testWithoutALockOnlyTheStoreIsTouched(): void
    {
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), new SessionManager($this->store, gcProbability: 0));
        $sessionId = $this->existingSession();

        $protocol->processInput(new RecordingTransport(), '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame(["read $sessionId", "read $sessionId", "write $sessionId"], $this->log);
    }

    #[TestDox('what a handler writes after it resumed is saved under the lock, as the code after a resume runs without it')]
    public function testSessionWritesAfterResumeAreSavedUnderTheLock(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $sessionId = $this->existingSession();
        $fiber = $this->suspendedHandler($this->resumingProtocol($lock), $sessionId);

        $this->log = [];
        $result = $fiber->resume(new Response(1000, []));

        $this->assertTrue($fiber->isTerminated());
        $this->assertNull($result);
        $this->assertInstanceOf(Response::class, $fiber->getReturn());
        $this->assertSame([
            "acquire $sessionId",
            "read $sessionId",
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
        $this->assertTrue((new Session($this->store, $sessionId))->get('after'));
    }

    #[TestDox('a resumed handler whose session stays locked still gets its response out, without its later writes')]
    public function testResumedHandlerOnABusySessionIsStillAnswered(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $sessionId = $this->existingSession();
        $fiber = $this->suspendedHandler($this->resumingProtocol($lock), $sessionId);

        $lock->acquirable = false;
        $this->log = [];
        $fiber->resume(new Response(1000, []));

        $this->assertInstanceOf(Response::class, $fiber->getReturn());
        $this->assertSame([], $this->log);
        $this->assertSame([], $lock->heldSessions());
        $this->assertNull((new Session($this->store, $sessionId))->get('after'));
    }

    #[TestDox('a session the client ended while the handler waited is not brought back by its later writes')]
    public function testSessionDestroyedWhileTheHandlerWaitedStaysDestroyed(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $sessionId = $this->existingSession();
        $protocol = $this->resumingProtocol($lock);
        $fiber = $this->suspendedHandler($protocol, $sessionId);

        $protocol->destroySession($sessionId);
        $fiber->resume(new Response(1000, []));

        $this->assertInstanceOf(Response::class, $fiber->getReturn());
        $this->assertFalse($this->store->exists($sessionId));
        $this->assertSame([], $lock->heldSessions());
    }

    private function resumingProtocol(RecordingSessionLock $lock): Protocol
    {
        $handler = new class implements RequestHandlerInterface {
            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response
            {
                $session->set('before', true);
                \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));
                $session->set('after', true);

                return new Response($request->getId(), []);
            }
        };

        return new Protocol(
            requestHandlers: [$handler],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: new SessionManager($this->store, gcProbability: 0),
            sessionLock: $lock,
        );
    }

    /**
     * @return \Fiber<null, mixed, mixed, mixed>
     */
    private function suspendedHandler(Protocol $protocol, Uuid $sessionId): \Fiber
    {
        $transport = new class extends InMemoryTransport {
            /** @var \Fiber<null, mixed, mixed, mixed>|null */
            public ?\Fiber $fiber = null;

            public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
            {
                $this->fiber = $fiber;
            }
        };

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertNotNull($transport->fiber);
        $this->assertTrue($transport->fiber->isSuspended());

        return $transport->fiber;
    }

    private function protocol(RecordingSessionLock $lock, LoggerInterface $logger = new NullLogger()): Protocol
    {
        return new Protocol(
            requestHandlers: [new PingHandler()],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: new SessionManager($this->store, gcProbability: 0),
            logger: $logger,
            sessionLock: $lock,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function existingSession(array $data = []): Uuid
    {
        $session = new Session($this->store, Uuid::v4());
        foreach ($data as $key => $value) {
            $session->set($key, $value);
        }
        $session->save();

        $this->log = [];

        return $session->getId();
    }
}
