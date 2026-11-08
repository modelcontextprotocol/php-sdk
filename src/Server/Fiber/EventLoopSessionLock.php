<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Fiber;

use Mcp\Server\Session\SessionLockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Makes a request wait on the event loop for a session lock held by another request of this process.
 *
 * A lock backend waits by blocking the process, so the request holding the lock could never finish.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class EventLoopSessionLock implements SessionLockInterface
{
    /** @var array<string, true> */
    private array $held = [];

    /** @var array<string, list<FiberSettlement>> */
    private array $waiting = [];

    public function __construct(
        private readonly SessionLockInterface $lock,
        private readonly FiberSchedulerInterface $scheduler,
    ) {
    }

    public function acquire(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        while (isset($this->held[$key])) {
            $settlement = new FiberSettlement();
            $this->waiting[$key][] = $settlement;
            $this->scheduler->wait($settlement);
        }

        $this->lock->acquire($sessionId);
        $this->held[$key] = true;
    }

    public function tryAcquire(Uuid $sessionId): bool
    {
        $key = $sessionId->toRfc4122();

        if (isset($this->held[$key]) || !$this->lock->tryAcquire($sessionId)) {
            return false;
        }

        $this->held[$key] = true;

        return true;
    }

    public function release(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        try {
            $this->lock->release($sessionId);
        } finally {
            $waiting = $this->waiting[$key] ?? [];
            unset($this->held[$key], $this->waiting[$key]);

            foreach ($waiting as $settlement) {
                $settlement->settle();
            }
        }
    }
}
