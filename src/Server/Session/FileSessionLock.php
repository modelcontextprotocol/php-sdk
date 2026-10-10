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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Exception\SessionLockException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * Session lock backed by `flock()` on one file per session.
 *
 * Works across the processes and threads of one machine, which is what
 * {@see FileSessionStore} serves. Lock files are never deleted on release:
 * unlinking a file another worker has open would let a third worker lock a new
 * file of the same name next to it. Pointed at the session directory,
 * {@see FileSessionStore::gc()} removes the lock files of expired sessions.
 *
 * @author Vitalii Cherepanov <vbcherepanov@gmail.com>
 */
final class FileSessionLock implements SessionLockInterface
{
    public const FILE_SUFFIX = '.lock';

    private const RETRY_DELAY_MICROSECONDS = 10_000;

    /** @var array<string, resource> */
    private array $handles = [];

    /**
     * @param float $timeout how long to wait for a lock held by another request, in seconds
     */
    public function __construct(
        private readonly string $directory,
        private readonly float $timeout = 30.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($this->timeout < 0) {
            throw new InvalidArgumentException('timeout must be greater than or equal to 0.');
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            $this->logger->warning('Failed to create session lock directory.', [
                'directory' => $this->directory,
                'error' => error_get_last()['message'] ?? 'unknown',
            ]);
        }

        if (!is_dir($this->directory) || !is_writable($this->directory)) {
            throw new RuntimeException(\sprintf('Session lock directory "%s" is not writable.', $this->directory));
        }
    }

    public function acquire(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        if (isset($this->handles[$key])) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is already held.', $key));
        }

        $path = $this->pathFor($sessionId);
        $handle = @fopen($path, 'c');
        if (false === $handle) {
            throw new SessionLockException(\sprintf('Failed to open the lock file of session "%s": %s', $key, error_get_last()['message'] ?? 'unknown error'));
        }

        $deadline = hrtime(true) + (int) ($this->timeout * 1_000_000_000);

        while (!flock($handle, \LOCK_EX | \LOCK_NB, $wouldBlock)) {
            if (1 !== $wouldBlock) {
                fclose($handle);

                throw new SessionLockException(\sprintf('Failed to lock the lock file of session "%s".', $key));
            }

            if (hrtime(true) >= $deadline) {
                fclose($handle);

                throw new SessionLockException(\sprintf('Timed out after %.3fs waiting for the lock of session "%s".', $this->timeout, $key));
            }

            usleep(self::RETRY_DELAY_MICROSECONDS);
        }

        // flock() leaves the modification time alone; refreshing it keeps the
        // file of a live session out of FileSessionStore::gc().
        @touch($path);

        $this->handles[$key] = $handle;
    }

    public function release(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        $handle = $this->handles[$key] ?? null;
        if (null === $handle) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is not held.', $key));
        }

        unset($this->handles[$key]);

        $unlocked = flock($handle, \LOCK_UN);
        fclose($handle);

        if (!$unlocked) {
            throw new SessionLockException(\sprintf('Failed to unlock the lock file of session "%s".', $key));
        }
    }

    private function pathFor(Uuid $sessionId): string
    {
        return $this->directory.\DIRECTORY_SEPARATOR.$sessionId->toRfc4122().self::FILE_SUFFIX;
    }
}
