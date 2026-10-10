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
use Mcp\Exception\TimeoutException;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Locks sessions with symfony/lock, so workers sharing a lock store (flock, Redis, a database, ...) take turns.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SymfonySessionLock implements SessionLockInterface
{
    /**
     * @var array<string, LockInterface>
     */
    private array $locks = [];

    /**
     * @param float $timeout seconds to wait for the lock before giving up
     * @param float $ttl     seconds after which the lock store expires a lock that was not released, e.g. by a crashed worker
     */
    public function __construct(
        private readonly LockFactory $factory,
        private readonly float $timeout = 30.0,
        private readonly float $ttl = 300.0,
        private readonly string $prefix = 'mcp-session-',
    ) {
        if ($timeout < 0) {
            throw new InvalidArgumentException('timeout must be greater than or equal to 0.');
        }
    }

    public function acquire(Uuid $id): void
    {
        $key = $id->toRfc4122();
        $lock = $this->factory->createLock($this->prefix.$key, $this->ttl);
        $deadline = microtime(true) + $this->timeout;

        try {
            while (!$lock->acquire()) {
                if (microtime(true) >= $deadline) {
                    throw new TimeoutException(\sprintf('Could not lock session "%s" within %s seconds.', $key, $this->timeout));
                }

                usleep(20000);
            }
        } catch (LockException $e) {
            throw new RuntimeException(\sprintf('Failed to lock session "%s": %s', $key, $e->getMessage()), 0, $e);
        }

        $this->locks[$key] = $lock;
    }

    public function release(Uuid $id): void
    {
        $key = $id->toRfc4122();

        if (!isset($this->locks[$key])) {
            return;
        }

        $lock = $this->locks[$key];
        unset($this->locks[$key]);

        try {
            $lock->release();
        } catch (LockException $e) {
            throw new RuntimeException(\sprintf('Failed to unlock session "%s": %s', $key, $e->getMessage()), 0, $e);
        }
    }
}
