<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Fiber;

use Mcp\Server\Exception\SessionLockException;
use Mcp\Server\Fiber\EventLoopSessionLock;
use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;
use Mcp\Server\Session\SessionLockInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EventLoopSessionLockTest extends TestCase
{
    public function testTryAcquireReportsALockHeldInThisProcessWithoutAskingTheBackend(): void
    {
        $backend = $this->createMock(SessionLockInterface::class);
        $backend->expects($this->never())->method('tryAcquire');

        $lock = new EventLoopSessionLock($backend, $this->createStub(FiberSchedulerInterface::class));
        $sessionId = Uuid::v4();
        $lock->acquire($sessionId);

        $this->assertFalse($lock->tryAcquire($sessionId));
    }

    public function testAFreeLockIsTakenWithoutWaiting(): void
    {
        $scheduler = $this->createMock(FiberSchedulerInterface::class);
        $scheduler->expects($this->never())->method('wait');

        $backend = $this->createStub(SessionLockInterface::class);
        $backend->method('tryAcquire')->willReturn(true);

        $lock = new EventLoopSessionLock($backend, $scheduler);
        $sessionId = Uuid::v4();

        $lock->acquire($sessionId);
        $lock->release($sessionId);
        $lock->acquire($sessionId);
        $lock->release($sessionId);

        $this->assertTrue($lock->tryAcquire($sessionId));
    }

    public function testReleaseWakesEveryWaitingRequest(): void
    {
        $settlements = [];
        $scheduler = $this->createStub(FiberSchedulerInterface::class);
        $scheduler->method('wait')->willReturnCallback(static function (FiberSettlement $settlement) use (&$settlements): void {
            $settlements[] = $settlement;
            \Fiber::suspend();
        });

        $lock = new EventLoopSessionLock($this->createStub(SessionLockInterface::class), $scheduler);
        $sessionId = Uuid::v4();
        $lock->acquire($sessionId);

        $waiters = [new \Fiber(static fn () => $lock->acquire($sessionId)), new \Fiber(static fn () => $lock->acquire($sessionId))];
        foreach ($waiters as $waiter) {
            $waiter->start();
        }
        $this->assertCount(2, $settlements);
        $this->assertFalse($settlements[0]->isSettled());

        $lock->release($sessionId);

        $this->assertTrue($settlements[0]->isSettled());
        $this->assertTrue($settlements[1]->isSettled());
    }

    public function testAFailingReleaseStillWakesTheWaitingRequests(): void
    {
        $settlements = [];
        $scheduler = $this->createStub(FiberSchedulerInterface::class);
        $scheduler->method('wait')->willReturnCallback(static function (FiberSettlement $settlement) use (&$settlements): void {
            $settlements[] = $settlement;
            \Fiber::suspend();
        });
        $backend = $this->createStub(SessionLockInterface::class);
        $backend->method('release')->willThrowException(new SessionLockException('The backend failed.'));

        $lock = new EventLoopSessionLock($backend, $scheduler);
        $sessionId = Uuid::v4();
        $lock->acquire($sessionId);
        $waiter = new \Fiber(static fn () => $lock->acquire($sessionId));
        $waiter->start();

        try {
            $lock->release($sessionId);
            $this->fail('The failure of the backend was swallowed.');
        } catch (SessionLockException) {
            $this->assertTrue($settlements[0]->isSettled());
        }
    }
}
