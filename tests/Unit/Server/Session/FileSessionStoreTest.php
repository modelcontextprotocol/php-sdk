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

use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Server\Session\FileSessionStore;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
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

    #[TestDox('gc() collects the temporary files of writes that never made it into place')]
    public function testGcCollectsOrphanedTemporaryFiles(): void
    {
        $store = new FileSessionStore($this->directory, ttl: 60);
        $id = new UuidV4();
        $store->write($id, 'payload');

        $orphan = $this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122().'.0123456789ab.tmp';
        file_put_contents($orphan, 'half a payload');
        touch($orphan, time() - 120);

        $fresh = $this->directory.\DIRECTORY_SEPARATOR.(new UuidV4())->toRfc4122().'.ba9876543210.tmp';
        file_put_contents($fresh, 'a write still in flight');

        $deleted = $store->gc();

        // The orphan belongs to a session that is still alive, so collecting it is not a session
        // deletion, and a write that is still running must be left alone.
        $this->assertSame([], $deleted);
        $this->assertFileDoesNotExist($orphan);
        $this->assertFileExists($fresh);
        $this->assertFileExists($this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122());
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

    #[TestDox('logs a warning when a session file cannot be read')]
    public function testUnreadableSessionFileLogsWarning(): void
    {
        $logger = new WarningCollectingLogger();
        $store = new FileSessionStore($this->directory, logger: $logger);
        $id = new UuidV4();

        $store->write($id, 'payload');

        $path = $this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122();
        chmod($path, 0000);
        clearstatcache(true, $path);

        if (is_readable($path)) {
            $this->markTestSkipped('Permission bits do not restrict reads here (running as root, or a filesystem that ignores them).');
        }

        $this->assertFalse($store->read($id));
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('Failed to read session file.', $logger->warnings[0]['message']);
        $this->assertSame($path, $logger->warnings[0]['context']['path']);
    }

    #[TestDox('logs a warning when a session file cannot be written')]
    public function testUnwritableSessionFileLogsWarning(): void
    {
        $logger = new WarningCollectingLogger();
        $store = new FileSessionStore($this->directory, logger: $logger);

        chmod($this->directory, 0555);
        clearstatcache(true, $this->directory);

        if (is_writable($this->directory)) {
            $this->markTestSkipped('Permission bits do not restrict writes here (running as root, or a filesystem that ignores them).');
        }

        $this->assertFalse($store->write(new UuidV4(), 'payload'));
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('Failed to write session file.', $logger->warnings[0]['message']);
    }

    #[TestDox('logs a warning when the session directory cannot be opened for garbage collection')]
    public function testGcLogsWarningWhenDirectoryVanished(): void
    {
        $logger = new WarningCollectingLogger();
        $store = new FileSessionStore($this->directory, logger: $logger);

        rmdir($this->directory);

        $this->assertSame([], $store->gc());
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('Failed to open session directory for garbage collection.', $logger->warnings[0]['message']);
        $this->assertSame($this->directory, $logger->warnings[0]['context']['directory']);
    }

    #[TestDox('gc() warns and does not report a session whose file survived deletion')]
    public function testGcLogsWarningWhenExpiredFileCannotBeDeleted(): void
    {
        $logger = new WarningCollectingLogger();
        $store = new FileSessionStore($this->directory, ttl: 60, logger: $logger);
        $id = new UuidV4();
        $store->write($id, 'payload');

        $path = $this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122();
        touch($path, time() - 120);

        chmod($this->directory, 0555);
        clearstatcache(true, $this->directory);

        if (is_writable($this->directory)) {
            $this->markTestSkipped('Permission bits do not restrict writes here (running as root, or a filesystem that ignores them).');
        }

        // The file is still there, so its id must not be reported as deleted.
        $this->assertSame([], $store->gc());
        $this->assertFileExists($path);
        $this->assertCount(1, $logger->warnings);
        $this->assertSame('Failed to delete expired session file.', $logger->warnings[0]['message']);
        $this->assertSame($path, $logger->warnings[0]['context']['path']);
    }

    #[TestDox('stays silent on the happy path')]
    public function testHappyPathLogsNothing(): void
    {
        $logger = new WarningCollectingLogger();
        $store = new FileSessionStore($this->directory, logger: $logger);
        $id = new UuidV4();

        $store->write($id, 'payload');
        $store->read($id);
        $store->destroy($id);
        $store->gc();

        $this->assertSame([], $logger->warnings);
    }

    #[TestDox('reads a zero-byte session file back as an empty string')]
    public function testReadReturnsEmptyStringForZeroByteFile(): void
    {
        $store = new FileSessionStore($this->directory);
        $id = new UuidV4();
        $store->write($id, '{"initialized":true}');

        file_put_contents($this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122(), '');

        $this->assertSame('', $store->read($id));
    }

    #[TestDox('never serves a partial payload while another process writes the same session')]
    public function testConcurrentWritesNeverPublishAPartialPayload(): void
    {
        if (!\function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is needed to run truly concurrent writers.');
        }

        $store = new FileSessionStore($this->directory);
        $id = new UuidV4();
        $store->write($id, '{"initialized":true}');

        $writers = [];
        for ($i = 0; $i < 3; ++$i) {
            $writers[] = $this->spawnWriter($id, $i);
        }

        // Read the session while the other processes write it. Every payload the store hands out
        // has to be complete: a writer must never be able to publish a file another one is still
        // filling, and never one it has just emptied.
        $reads = 0;
        $partial = 0;
        $deadline = microtime(true) + 20.0;
        do {
            $raw = $store->read($id);
            ++$reads;

            if (false !== $raw && !\is_array(json_decode($raw, true))) {
                ++$partial;
            }

            $running = false;
            foreach ($writers as $writer) {
                $running = $running || proc_get_status($writer)['running'];
            }
        } while ($running && microtime(true) < $deadline);

        foreach ($writers as $i => $writer) {
            $this->assertSame(0, proc_close($writer));
            $this->assertSame('', file_get_contents($this->directory.\DIRECTORY_SEPARATOR.'writer-'.$i.'.err'));
        }

        $this->assertSame(0, $partial, \sprintf('%d of %d reads returned a partial session payload.', $partial, $reads));
    }

    /**
     * Writes the same session from another process, so the writes really do overlap.
     *
     * @return resource
     */
    private function spawnWriter(UuidV4 $id, int $index)
    {
        $script = <<<'PHP'
            <?php

            require $argv[1];

            $store = new Mcp\Server\Session\FileSessionStore($argv[2]);
            $id = Symfony\Component\Uid\Uuid::fromString($argv[3]);
            $payload = json_encode(['queue' => array_fill(0, 200, str_repeat('x', 200))]);

            for ($i = 0; $i < 200; ++$i) {
                $store->write($id, $payload);
            }
            PHP;

        $file = $this->directory.\DIRECTORY_SEPARATOR.'writer.php';
        file_put_contents($file, $script);

        $process = proc_open(
            [\PHP_BINARY, $file, \dirname(__DIR__, 4).'/vendor/autoload.php', $this->directory, $id->toRfc4122()],
            [
                1 => ['file', $this->directory.\DIRECTORY_SEPARATOR.'writer-'.$index.'.out', 'w'],
                2 => ['file', $this->directory.\DIRECTORY_SEPARATOR.'writer-'.$index.'.err', 'w'],
            ],
            $pipes,
        );

        $this->assertIsResource($process);

        return $process;
    }
}

/**
 * Keeps every warning with its context, so a silent failure can be told apart
 * from one the operator was told about.
 */
final class WarningCollectingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $warnings = [];

    /**
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
