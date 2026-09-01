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

use Mcp\Server\Exception\ExceptionInterface;
use Mcp\Server\Exception\RuntimeException;
use Mcp\Server\Session\FileSessionStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

class FileSessionStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mcp-file-session-store-'.bin2hex(random_bytes(6));
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

    #[TestDox('creates the session directory when it does not exist yet')]
    public function testCreatesMissingDirectory(): void
    {
        new FileSessionStore($this->directory);

        $this->assertDirectoryExists($this->directory);
    }

    #[TestDox('round-trips a session payload through the filesystem')]
    public function testWriteThenRead(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = new UuidV4();

        $store->write($id, 'payload');

        $this->assertTrue($store->exists($id));
        $this->assertSame('payload', $store->read($id));
    }

    #[TestDox('gc() only deletes expired session files, never foreign files in the directory')]
    public function testGcLeavesForeignFilesAlone(): void
    {
        $store = new FileSessionStore($this->directory, ttl: 60);
        $id = new UuidV4();
        $store->write($id, 'payload');

        $foreign = $this->directory.'/important.lock';
        file_put_contents($foreign, 'not a session');

        // Make everything stale
        $expired = time() - 120;
        touch($this->directory.'/'.$id->toRfc4122(), $expired);
        touch($foreign, $expired);

        $deleted = $store->gc();

        $this->assertEquals([$id], $deleted);
        $this->assertFileDoesNotExist($this->directory.'/'.$id->toRfc4122());
        $this->assertFileExists($foreign);
    }

    #[TestDox('rejects an unwritable directory with the SDK\'s own exception')]
    public function testUnwritableDirectoryThrowsPackageException(): void
    {
        mkdir($this->directory, 0775, true);
        chmod($this->directory, 0555);
        clearstatcache(true, $this->directory);

        if (is_writable($this->directory)) {
            $this->markTestSkipped('Permission bits do not restrict writes here (running as root, or a filesystem that ignores them).');
        }

        // The store's only throw must stay inside the package hierarchy, so a
        // consumer catching ExceptionInterface sees it rather than a bare SPL
        // RuntimeException escaping the SDK.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('Session directory "%s" is not writable.', $this->directory));

        try {
            new FileSessionStore($this->directory);
        } catch (RuntimeException $e) {
            $this->assertInstanceOf(ExceptionInterface::class, $e);

            throw $e;
        }
    }
}
