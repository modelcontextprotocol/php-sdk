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
use Mcp\Exception\RuntimeException;
use Mcp\Exception\SessionLockException;
use Mcp\Server\Session\FileSessionLock;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FileSessionLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mcp-file-session-lock-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        @chmod($this->directory, 0775);

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    #[TestDox('creates the lock directory when it does not exist yet')]
    public function testCreatesMissingDirectory(): void
    {
        new FileSessionLock($this->directory);

        $this->assertDirectoryExists($this->directory);
    }

    #[TestDox('rejects an unwritable directory with the SDK\'s own exception')]
    public function testUnwritableDirectoryThrows(): void
    {
        mkdir($this->directory, 0775, true);
        chmod($this->directory, 0555);
        clearstatcache(true, $this->directory);

        if (is_writable($this->directory)) {
            $this->markTestSkipped('The current user can write to a read-only directory (probably root).');
        }

        $this->expectException(RuntimeException::class);

        new FileSessionLock($this->directory);
    }

    #[TestDox('rejects a negative timeout')]
    public function testNegativeTimeoutThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FileSessionLock($this->directory, timeout: -1);
    }

    #[TestDox('holds a lock file named after the session, and keeps it after release')]
    public function testAcquireCreatesALockFilePerSession(): void
    {
        $lock = new FileSessionLock($this->directory);
        $id = Uuid::v4();

        $lock->acquire($id);
        $this->assertFileExists($this->directory.'/'.$id->toRfc4122().FileSessionLock::FILE_SUFFIX);

        $lock->release($id);
        $this->assertFileExists($this->directory.'/'.$id->toRfc4122().FileSessionLock::FILE_SUFFIX);
    }

    #[TestDox('another worker cannot acquire a held lock, and can once it is released')]
    public function testHeldLockBlocksAnotherWorkerUntilReleased(): void
    {
        $id = Uuid::v4();
        $first = new FileSessionLock($this->directory, timeout: 0.05);
        $second = new FileSessionLock($this->directory, timeout: 0.05);

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

    #[TestDox('a zero timeout tries once')]
    public function testZeroTimeoutDoesNotWait(): void
    {
        $id = Uuid::v4();
        $first = new FileSessionLock($this->directory);
        $second = new FileSessionLock($this->directory, timeout: 0);

        $first->acquire($id);

        $start = hrtime(true);
        try {
            $second->acquire($id);
            $this->fail('The lock was acquired twice.');
        } catch (SessionLockException) {
            $this->assertLessThan(1_000_000_000, hrtime(true) - $start);
        }

        $first->release($id);
    }

    #[TestDox('locks of different sessions do not block each other')]
    public function testDifferentSessionsDoNotBlockEachOther(): void
    {
        $this->expectNotToPerformAssertions();

        $first = new FileSessionLock($this->directory, timeout: 0.05);
        $second = new FileSessionLock($this->directory, timeout: 0.05);
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
        $lock = new FileSessionLock($this->directory, timeout: 0.05);
        $id = Uuid::v4();
        $lock->acquire($id);

        $this->expectException(SessionLockException::class);
        $this->expectExceptionMessage('already held');

        $lock->acquire($id);
    }

    #[TestDox('releasing a lock that is not held is an error')]
    public function testReleasingAnUnheldLockThrows(): void
    {
        $lock = new FileSessionLock($this->directory);

        $this->expectException(SessionLockException::class);
        $this->expectExceptionMessage('not held');

        $lock->release(Uuid::v4());
    }

    #[TestDox('a lock can be acquired again by the same instance after release')]
    public function testReacquireAfterRelease(): void
    {
        $this->expectNotToPerformAssertions();

        $lock = new FileSessionLock($this->directory, timeout: 0.05);
        $id = Uuid::v4();

        $lock->acquire($id);
        $lock->release($id);
        $lock->acquire($id);
        $lock->release($id);
    }

    #[TestDox('acquiring refreshes the lock file\'s modification time, so gc() keeps the file of a live session')]
    public function testAcquireTouchesTheLockFile(): void
    {
        $lock = new FileSessionLock($this->directory);
        $id = Uuid::v4();
        $path = $this->directory.'/'.$id->toRfc4122().FileSessionLock::FILE_SUFFIX;

        touch($path, time() - 7200);
        clearstatcache(true, $path);
        $this->assertLessThan(time() - 3600, filemtime($path));

        $lock->acquire($id);
        $lock->release($id);

        clearstatcache(true, $path);
        $this->assertGreaterThan(time() - 60, filemtime($path));
    }
}
