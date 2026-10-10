<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session;

use Mcp\Exception\TimeoutException;
use Mcp\Server\Session\SymfonySessionLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

final class SymfonySessionLockTest extends TestCase
{
    public function testAcquireGivesUpAfterTimeout(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $id = Uuid::v4();
        $holder = new SymfonySessionLock($factory);
        $holder->acquire($id);

        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage(\sprintf('Could not lock session "%s" within 0.05 seconds.', $id->toRfc4122()));

        (new SymfonySessionLock($factory, timeout: 0.05))->acquire($id);
    }

    public function testReleaseLetsAnotherWorkerAcquire(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $id = Uuid::v4();
        $first = new SymfonySessionLock($factory);
        $first->acquire($id);
        $first->release($id);

        $second = new SymfonySessionLock($factory, timeout: 0.0);
        $second->acquire($id);
        $second->release($id);

        $this->expectNotToPerformAssertions();
    }

    public function testLocksSessionsIndependently(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $holder = new SymfonySessionLock($factory);
        $holder->acquire(Uuid::v4());

        (new SymfonySessionLock($factory, timeout: 0.0))->acquire(Uuid::v4());

        $this->expectNotToPerformAssertions();
    }

    public function testReleaseWithoutAcquireIsNoop(): void
    {
        (new SymfonySessionLock(new LockFactory(new InMemoryStore())))->release(Uuid::v4());

        $this->expectNotToPerformAssertions();
    }
}
