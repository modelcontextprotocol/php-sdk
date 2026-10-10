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
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionLockInterface;
use Mcp\Server\Session\SessionManager;
use Mcp\Server\Session\SymfonySessionLock;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\TransportInterface;
use Mcp\Tests\Unit\Fixtures\RecordingTransport;
use Mcp\Tests\Unit\Server\Session\Fixture\FiberSessionLock;
use Mcp\Tests\Unit\Server\Session\Fixture\InterleavingSessionStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

/**
 * Two workers handling a request of the same session at the same time, both incrementing one session key.
 */
final class ProtocolSessionLockTest extends TestCase
{
    private const INCREMENT = '{"jsonrpc": "2.0", "id": %d, "method": "ping"}';

    #[TestDox('without a session lock, concurrent requests lose an increment of the same key')]
    public function testConcurrentIncrementsAreLostWithoutLock(): void
    {
        $this->assertSame(1, $this->incrementConcurrently(null));
    }

    #[TestDox('with a session lock, concurrent requests take turns and keep both increments')]
    public function testSessionLockKeepsConcurrentIncrements(): void
    {
        $this->assertSame(2, $this->incrementConcurrently(new FiberSessionLock()));
    }

    #[TestDox('a suspended request releases the session lock while it waits on the client')]
    public function testSuspendedRequestReleasesSessionLock(): void
    {
        [$sessions, $sessionId] = $this->createSession(new InterleavingSessionStore());
        $lock = new FiberSessionLock();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturnCallback(static function (Request $request, SessionInterface $session): Response {
            $answer = \Fiber::suspend(new RequestSuspension(new PingRequest(), $session->getId()->toRfc4122(), 5));

            return new Response($request->getId(), $answer instanceof Response ? $answer->result : null);
        });

        $fiber = null;
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('attachFiberToSession')
            ->willReturnCallback(static function (\Fiber $attached) use (&$fiber): void {
                $fiber = $attached;
            });

        $waiting = new Protocol([$handler], [], MessageFactory::make(), $sessions, sessionLock: $lock);
        $waiting->processInput($transport, \sprintf(self::INCREMENT, 1), $sessionId);

        $this->assertInstanceOf(\Fiber::class, $fiber);
        $this->assertTrue($fiber->isSuspended());
        $this->assertFalse($lock->isLocked($sessionId));

        // The client's answer arrives on another worker, which can lock the session; outside of a fiber it could not wait.
        $answering = new Protocol([], [], MessageFactory::make(), $sessions, sessionLock: $lock);
        $answering->processInput(new RecordingTransport(), '{"jsonrpc": "2.0", "id": 1000, "result": {"ok": true}}', $sessionId);

        // The polling worker takes the answer under the lock before resuming the fiber with it.
        $answer = $waiting->checkResponse(1000, $sessionId);
        $this->assertInstanceOf(Response::class, $answer);
        $this->assertFalse($lock->isLocked($sessionId));
        $this->assertSame(['acquire', 'release', 'acquire', 'release', 'acquire', 'release'], $lock->log);

        $fiber->resume($answer);
        $this->assertTrue($fiber->isTerminated());
    }

    #[TestDox('a request that cannot lock its session in time is answered with an internal error')]
    public function testLockTimeoutAnswersWithInternalError(): void
    {
        [$sessions, $sessionId] = $this->createSession(new InterleavingSessionStore());
        $factory = new LockFactory(new InMemoryStore());
        $held = $factory->createLock('mcp-session-'.$sessionId->toRfc4122());
        $this->assertTrue($held->acquire());

        $transport = new RecordingTransport();
        $protocol = new Protocol([$this->createIncrementHandler()], [], MessageFactory::make(), $sessions, sessionLock: new SymfonySessionLock($factory, timeout: 0.05));
        $protocol->processInput($transport, \sprintf(self::INCREMENT, 1), $sessionId);

        $this->assertCount(1, $transport->sent);
        $this->assertSame(Error::INTERNAL_ERROR, json_decode($transport->sent[0]['message'], true)['error']['code']);
        $this->assertNull($sessions->createWithId($sessionId)->get('counter'));
    }

    private function incrementConcurrently(?SessionLockInterface $lock): int
    {
        $store = new InterleavingSessionStore();
        [$sessions, $sessionId] = $this->createSession($store);

        $first = new Protocol([$this->createIncrementHandler()], [], MessageFactory::make(), $sessions, sessionLock: $lock);
        $second = new Protocol([$this->createIncrementHandler()], [], MessageFactory::make(), $sessions, sessionLock: $lock);

        // The second request arrives right after the first one read the session. Each runs in its
        // own fiber, so a request waiting on the lock suspends where a worker would block.
        $store->interleaveAfterNextRead(static function () use ($second, $sessionId): void {
            (new \Fiber(static fn () => $second->processInput(new RecordingTransport(), \sprintf(self::INCREMENT, 2), $sessionId)))->start();
        });

        (new \Fiber(static fn () => $first->processInput(new RecordingTransport(), \sprintf(self::INCREMENT, 1), $sessionId)))->start();

        return $sessions->createWithId($sessionId)->get('counter');
    }

    /**
     * @return array{SessionManager, Uuid}
     */
    private function createSession(InterleavingSessionStore $store): array
    {
        $sessions = new SessionManager($store, gcProbability: 0);
        $sessionId = Uuid::v4();
        $session = $sessions->createWithId($sessionId);
        $session->set('initialized', true);
        $session->save();

        return [$sessions, $sessionId];
    }

    /**
     * @return RequestHandlerInterface<EmptyResult>
     */
    private function createIncrementHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): Response
            {
                $session->set('counter', $session->get('counter', 0) + 1);

                return new Response($request->getId(), new EmptyResult());
            }
        };
    }
}
