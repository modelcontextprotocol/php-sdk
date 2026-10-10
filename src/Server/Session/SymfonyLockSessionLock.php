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
use Mcp\Exception\SessionLockException;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockExceptionInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Session lock backed by a symfony/lock store, for sessions shared across machines.
 *
 * Pairs with {@see Psr16SessionStore}: a Redis, database or Zookeeper lock
 * store reaches every worker that reaches the cache. Each lock expires after
 * `$ttl`, so a worker that dies holding one does not block the session for good.
 *
 * @author Vitalii Cherepanov <vbcherepanov@gmail.com>
 */
final class SymfonyLockSessionLock implements SessionLockInterface
{
    private const RETRY_DELAY_MICROSECONDS = 10_000;

    /** @var array<string, LockInterface> */
    private array $locks = [];

    /**
     * @param float      $timeout how long to wait for a lock held by another request, in seconds
     * @param float|null $ttl     how long a held lock lives before the store expires it, in seconds; null for no expiration
     * @param string     $prefix  prepended to the session id to form the lock resource name
     */
    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly float $timeout = 30.0,
        private readonly ?float $ttl = 300.0,
        private readonly string $prefix = 'mcp-session-',
    ) {
        if ($this->timeout < 0) {
            throw new InvalidArgumentException('timeout must be greater than or equal to 0.');
        }

        if (null !== $this->ttl && $this->ttl <= 0) {
            throw new InvalidArgumentException('ttl must be greater than 0, or null.');
        }
    }

    public function acquire(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        if (isset($this->locks[$key])) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is already held.', $key));
        }

        $lock = $this->lockFactory->createLock($this->prefix.$key, $this->ttl);
        $deadline = hrtime(true) + (int) ($this->timeout * 1_000_000_000);

        try {
            while (!$lock->acquire()) {
                if (hrtime(true) >= $deadline) {
                    throw new SessionLockException(\sprintf('Timed out after %.3fs waiting for the lock of session "%s".', $this->timeout, $key));
                }

                usleep(self::RETRY_DELAY_MICROSECONDS);
            }
        } catch (LockExceptionInterface $e) {
            throw new SessionLockException(\sprintf('Failed to acquire the lock of session "%s": %s', $key, $e->getMessage()), 0, $e);
        }

        $this->locks[$key] = $lock;
    }

    public function release(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        $lock = $this->locks[$key] ?? null;
        if (null === $lock) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is not held.', $key));
        }

        unset($this->locks[$key]);

        try {
            $lock->release();
        } catch (LockExceptionInterface $e) {
            throw new SessionLockException(\sprintf('Failed to release the lock of session "%s": %s', $key, $e->getMessage()), 0, $e);
        }
    }
}
