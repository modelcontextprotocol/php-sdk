<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * File-based session store.
 * Stores each session as a file named by the RFC4122 UUID, with the payload.
 */
class FileSessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 3600,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new \RuntimeException(\sprintf('Session directory "%s" is not writable.', $this->directory));
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

    public function read(Uuid $sessionId): string|false
    {
        $path = $this->pathFor($sessionId);

        if (!is_file($path)) {
            return false;
        }

        $mtime = @filemtime($path) ?: 0;
        if (($this->clock->now()->getTimestamp() - $mtime) > $this->ttl) {
            @unlink($path);

            return false;
        }

        $data = @file_get_contents($path);
        if (false === $data) {
            return false;
        }

        return $data;
    }

    public function write(Uuid $sessionId, string $data): bool
    {
        $path = $this->pathFor($sessionId);

        $tmp = $path.'.tmp';
        if (false === @file_put_contents($tmp, $data, \LOCK_EX)) {
            return false;
        }

        // Atomic move
        if (!@rename($tmp, $path)) {
            // Fallback if rename fails cross-device
            if (false === @copy($tmp, $path)) {
                @unlink($tmp);

                return false;
            }
            @unlink($tmp);
        }

        @touch($path, $this->clock->now()->getTimestamp());

        return true;
    }

    public function destroy(Uuid $sessionId): bool
    {
        $path = $this->pathFor($sessionId);

        if (is_file($path)) {
            @unlink($path);
        }

        return true;
    }

    /**
     * Remove sessions older than the configured TTL.
     * Returns an array of deleted session IDs (UUID instances).
     */
    public function gc(): array
    {
        $deleted = [];
        $now = $this->clock->now()->getTimestamp();

        $dir = @opendir($this->directory);
        if (false === $dir) {
            return $deleted;
        }

        while (($entry = readdir($dir)) !== false) {
            // Skip dot entries
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $this->directory.\DIRECTORY_SEPARATOR.$entry;
            if (!is_file($path)) {
                continue;
            }

            $mtime = @filemtime($path) ?: 0;
            if (($now - $mtime) > $this->ttl) {
                @unlink($path);
                try {
                    $deleted[] = Uuid::fromString($entry);
                } catch (\Throwable) {
                    // ignore non-UUID file names
                }
            }
        }

        closedir($dir);

        return $deleted;
    }

    private function pathFor(Uuid $id): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$id->toRfc4122();
    }
}
