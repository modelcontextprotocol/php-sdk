<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Mcp\Exception\RuntimeException;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * File-based session store.
 * Stores each session as a file named by the RFC4122 UUID, with the payload.
 * gc() scans the whole directory, so its cost grows with the number of sessions.
 */
class FileSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 3600,
        private readonly ClockInterface $clock = new NativeClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger->warning('Failed to create session directory.', [
                'directory' => $this->directory,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);
        }

        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException(\sprintf('Session directory "%s" is not writable.', $this->directory));
        }
    }

    public function exists(Uuid $id): bool
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            return false;
        }

        $mtime = @filemtime($path) ?: 0;

        return ($this->clock->now()->getTimestamp() - $mtime) <= $this->ttl;
    }

    public function read(Uuid $id): string|false
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            return false;
        }

        $mtime = @filemtime($path) ?: 0;
        if (($this->clock->now()->getTimestamp() - $mtime) > $this->ttl) {
            if (!@unlink($path) && is_file($path)) {
                $this->logger->warning('Failed to delete expired session file.', [
                    'path' => $path,
                    'error' => error_get_last()['message'] ?? 'unknown',
                ]);
            }

            return false;
        }

        $data = @file_get_contents($path);
        if (false === $data) {
            $this->logger->warning('Failed to read session file.', [
                'path' => $path,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);

            return false;
        }

        if ('' === $data) {
            $this->logger->warning('Ignored an empty session file.', ['path' => $path]);

            return false;
        }

        return $data;
    }

    public function write(Uuid $id, string $data): bool
    {
        $path = $this->pathFor($id);

        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($tmp, $data)) {
            $this->logger->warning('Failed to write session file.', [
                'path' => $tmp,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);

            return false;
        }

        // Atomic move
        if (!@rename($tmp, $path)) {
            // Fallback if rename fails cross-device
            if (false === @copy($tmp, $path)) {
                $this->logger->warning('Failed to move session file into place.', [
                    'path' => $path,
                    'error' => error_get_last()['message'] ?? 'unknown',
                ]);
                @unlink($tmp);

                return false;
            }
            @unlink($tmp);
        }

        @touch($path, $this->clock->now()->getTimestamp());

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        $path = $this->pathFor($id);

        if (is_file($path) && !@unlink($path) && is_file($path)) {
            $this->logger->warning('Failed to delete session file.', [
                'path' => $path,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);
        }

        return true;
    }

    /**
     * Remove sessions older than the configured TTL, along with the temporary files
     * of writes that never made it into place and the lock files a {@see FileSessionLock}
     * in the same directory left behind.
     * Returns an array of deleted session IDs (UUID instances).
     */
    public function gc(): array
    {
        $deleted = [];
        $now = $this->clock->now()->getTimestamp();

        $dir = @opendir($this->directory);
        if (false === $dir) {
            $this->logger->warning('Failed to open session directory for garbage collection.', [
                'directory' => $this->directory,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);

            return $deleted;
        }

        while (($entry = readdir($dir)) !== false) {
            // Skip dot entries
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $this->directory.\DIRECTORY_SEPARATOR.$entry;

            if ($this->isLockFile($entry)) {
                $this->deleteExpiredLockFile($path, $now);

                continue;
            }

            $isSession = Uuid::isValid($entry);
            if (!$isSession && !$this->isTemporaryFile($entry)) {
                continue;
            }

            if (!is_file($path)) {
                continue;
            }

            $mtime = @filemtime($path) ?: 0;
            if (($now - $mtime) > $this->ttl) {
                if (!@unlink($path) && is_file($path)) {
                    $this->logger->warning('Failed to delete expired session file.', [
                        'path' => $path,
                        'error' => error_get_last()['message'] ?? 'unknown',
                    ]);

                    continue;
                }

                if ($isSession) {
                    $deleted[] = Uuid::fromString($entry);
                }
            }
        }

        closedir($dir);

        return $deleted;
    }

    /**
     * Unlinks the file only while holding its lock: a request that holds the lock keeps its
     * file however long it runs, and one waiting for it notices the file is gone once it gets
     * the lock, see {@see FileSessionLock}.
     */
    private function deleteExpiredLockFile(string $path, int $now): void
    {
        if (!is_file($path) || ($now - (@filemtime($path) ?: 0)) <= $this->ttl) {
            return;
        }

        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return;
        }

        try {
            if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
                return;
            }

            // Checked again under the lock: the file may have been locked, touched and released,
            // or replaced, since it was first looked at.
            if (!FileSessionLock::isFileAt($handle, $path) || ($now - (@filemtime($path) ?: 0)) <= $this->ttl) {
                return;
            }

            if (!@unlink($path) && is_file($path)) {
                $this->logger->warning('Failed to delete expired session lock file.', [
                    'path' => $path,
                    'error' => error_get_last()['message'] ?? 'unknown',
                ]);
            }
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    private function isTemporaryFile(string $entry): bool
    {
        if (1 !== preg_match('/^(?<id>[^.]+)\\.[0-9a-f]{12}\\.tmp$/', $entry, $matches)) {
            return false;
        }

        return Uuid::isValid($matches['id']);
    }

    private function isLockFile(string $entry): bool
    {
        if (!str_ends_with($entry, FileSessionLock::FILE_SUFFIX)) {
            return false;
        }

        return Uuid::isValid(substr($entry, 0, -\strlen(FileSessionLock::FILE_SUFFIX)));
    }

    private function pathFor(Uuid $id): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122();
    }
}
