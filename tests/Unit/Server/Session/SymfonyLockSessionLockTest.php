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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\SessionLockException;
use Mcp\Server\Session\SymfonyLockSessionLock;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

final class SymfonyLockSessionLockTest extends TestCase
{
    #[TestDox('rejects a negative timeout')]
    public function testNegativeTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SymfonyLockSessionLock(new LockFactory(new InMemoryStore()), timeout: -1);
    }

    #[TestDox('rejects a non-positive ttl')]
    public function testNonPositiveTtlThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SymfonyLockSessionLock(new LockFactory(new InMemoryStore()), ttl: 0);
    }

    #[TestDox('another worker cannot acquire a held lock, and can once it is released')]
    public function testHeldLockBlocksAnotherWorkerUntilReleased(): void
    {
        $store = new InMemoryStore();
        $id = Uuid::v4();
        $first = new SymfonyLockSessionLock(new LockFactory($store), timeout: 0.05);
        $second = new SymfonyLockSessionLock(new LockFactory($store), timeout: 0.05);

        $first->acquire($id);

        try {
            $second->acquire($id);
            $this->fail('The lock was acquired twice.');
        } catch (SessionLockException $e) {
            $this->assertStringContainsString('Timed out after 0.050s', $e->getMessage());
            $this->assertStringContainsString($id->toRfc4122(), $e->getMessage());
        }

        $first->release($id);

        $second->acquire($id);
        $second->release($id);
    }

    #[TestDox('locks of different sessions do not block each other')]
    public function testDifferentSessionsDoNotBlockEachOther(): void
    {
        $this->expectNotToPerformAssertions();

        $store = new InMemoryStore();
        $first = new SymfonyLockSessionLock(new LockFactory($store), timeout: 0.05);
        $second = new SymfonyLockSessionLock(new LockFactory($store), timeout: 0.05);
        $a = Uuid::v4();
        $b = Uuid::v4();

        $first->acquire($a);
        $second->acquire($b);

        $first->release($a);
        $second->release($b);
    }

    #[TestDox('acquiring a lock this instance already holds is an error, not a deadlock')]
    public function testAcquiringTwiceThrows(): void
    {
        $lock = new SymfonyLockSessionLock(new LockFactory(new InMemoryStore()), timeout: 0.05);
        $id = Uuid::v4();
        $lock->acquire($id);

        $this->expectException(SessionLockException::class);
        $this->expectExceptionMessage('already held');

        $lock->acquire($id);
    }

    #[TestDox('releasing a lock that is not held is an error')]
    public function testReleasingAnUnheldLockThrows(): void
    {
        $lock = new SymfonyLockSessionLock(new LockFactory(new InMemoryStore()));

        $this->expectException(SessionLockException::class);
        $this->expectExceptionMessage('not held');

        $lock->release(Uuid::v4());
    }

    #[TestDox('names the lock resource after the prefix and the session id, with the configured ttl')]
    public function testLockResourceAndTtl(): void
    {
        $saved = [];
        $held = false;
        $store = $this->createMock(PersistingStoreInterface::class);
        $store->method('save')->willReturnCallback(static function (Key $key) use (&$saved, &$held): void {
            $saved[] = (string) $key;
            $held = true;
        });
        $store->method('delete')->willReturnCallback(static function () use (&$held): void {
            $held = false;
        });
        $store->method('exists')->willReturnCallback(static function () use (&$held): bool {
            return $held;
        });
        $store->expects($this->once())->method('putOffExpiration')->with($this->anything(), 42.0);

        $lock = new SymfonyLockSessionLock(new LockFactory($store), ttl: 42.0, prefix: 'app-mcp-');
        $id = Uuid::v4();

        $lock->acquire($id);

        $this->assertSame(['app-mcp-'.$id->toRfc4122()], $saved);
    }

    #[TestDox('a failing store surfaces as the SDK\'s exception')]
    public function testStoreFailureIsWrapped(): void
    {
        $store = $this->createMock(PersistingStoreInterface::class);
        $store->method('save')->willThrowException(new LockAcquiringException('redis is down'));
        $store->method('exists')->willReturn(false);

        $lock = new SymfonyLockSessionLock(new LockFactory($store), timeout: 0.05);

        try {
            $lock->acquire(Uuid::v4());
            $this->fail('The lock was acquired against a failing store.');
        } catch (SessionLockException $e) {
            $this->assertStringContainsString('Failed to acquire the lock of session', $e->getMessage());
            $this->assertInstanceOf(LockAcquiringException::class, $e->getPrevious());
            $this->assertSame('redis is down', $e->getPrevious()->getPrevious()?->getMessage());
        }
    }
}
