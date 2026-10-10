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
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\Handler\Request\PingHandler;
use Mcp\Server\Protocol;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Tests\Unit\Fixtures\RecordingTransport;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingSessionLock;
use Mcp\Tests\Unit\Server\Session\Fixture\RecordingSessionStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
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
        $sessionManager->method('exists')->willThrowException(new \RuntimeException('store down'));
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), $sessionManager, sessionLock: $lock);
        $sessionId = Uuid::v4();
        $transport = new RecordingTransport();

        $protocol->processInput($transport, '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame(["acquire $sessionId", "release $sessionId"], $this->log);
        $this->assertSame([], $lock->heldSessions());
        $this->assertCount(1, $transport->sent);
        $this->assertSame(Error::INTERNAL_ERROR, json_decode($transport->sent[0]['message'], true)['error']['code']);
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

    #[TestDox('consuming the outgoing queue locks around its read and save')]
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
            "acquire $sessionId",
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
        $this->assertSame(["acquire $sessionId", "read $sessionId", "release $sessionId"], $this->log);
    }

    #[TestDox('consuming a client response locks around its read and save')]
    public function testCheckingForAResponseLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession([
            '_mcp.pending_requests' => [7 => ['request_id' => 7, 'timeout' => 120, 'timestamp' => time()]],
            '_mcp.responses' => [7 => ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]]],
        ]);

        $this->assertInstanceOf(Response::class, $protocol->checkResponse(7, $sessionId));
        $this->assertSame([
            "acquire $sessionId",
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
        $sessionId = $this->existingSession([
            '_mcp.outgoing_queue' => [['message' => '{"jsonrpc":"2.0","method":"notifications/message"}', 'context' => ['type' => 'notification']]],
            '_mcp.pending_requests' => [7 => ['request_id' => 7, 'timeout' => 120, 'timestamp' => time()]],
            '_mcp.responses' => [7 => ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]]],
        ]);

        $this->assertSame([], $protocol->consumeOutgoingMessages($sessionId));
        $this->assertNull($protocol->checkResponse(7, $sessionId));
        $this->assertSame([], $this->log);

        $session = new Session($this->store, $sessionId);
        $this->assertCount(1, $session->get('_mcp.outgoing_queue'));
        $this->assertArrayHasKey(7, $session->get('_mcp.responses'));
    }

    #[TestDox('storing the request a resumed fiber sends locks around its read and save')]
    public function testFiberYieldLocks(): void
    {
        $lock = new RecordingSessionLock($this->log);
        $protocol = $this->protocol($lock);
        $sessionId = $this->existingSession();

        $requestId = $protocol->handleFiberYield(new RequestSuspension(new PingRequest(), $sessionId->toRfc4122(), 5), $sessionId);

        $this->assertSame(1000, $requestId);
        $this->assertSame([
            "acquire $sessionId",
            "read $sessionId",
            "write $sessionId",
            "release $sessionId",
        ], $this->log);
        $this->assertArrayHasKey(1000, (new Session($this->store, $sessionId))->get('_mcp.pending_requests'));
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

    #[TestDox('without a lock configured, nothing but the store is touched')]
    public function testWithoutALockOnlyTheStoreIsTouched(): void
    {
        $protocol = new Protocol([new PingHandler()], [], MessageFactory::make(), new SessionManager($this->store, gcProbability: 0));
        $sessionId = $this->existingSession();

        $protocol->processInput(new RecordingTransport(), '{"jsonrpc":"2.0","id":1,"method":"ping"}', $sessionId);

        $this->assertSame(["read $sessionId", "write $sessionId"], $this->log);
    }

    private function protocol(RecordingSessionLock $lock): Protocol
    {
        return new Protocol(
            requestHandlers: [new PingHandler()],
            notificationHandlers: [],
            messageFactory: MessageFactory::make(),
            sessionManager: new SessionManager($this->store, gcProbability: 0),
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
