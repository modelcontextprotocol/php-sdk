<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session\Fixture;

use Mcp\Exception\SessionLockTimeoutException;
use Mcp\Server\Session\SessionLockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Wraps a lock whose blocking acquire() fails once $heldElsewhere is set, like a lock another
 * worker holds past the timeout; tryAcquire() still reaches the wrapped lock, so a stream keeps polling.
 */
final class HeldElsewhereSessionLock implements SessionLockInterface
{
    public bool $heldElsewhere = false;

    public function __construct(
        private readonly SessionLockInterface $lock,
    ) {
    }

    public function acquire(Uuid $sessionId): void
    {
        if ($this->heldElsewhere) {
            throw new SessionLockTimeoutException(\sprintf('Timed out waiting for the lock of session "%s".', $sessionId->toRfc4122()));
        }

        $this->lock->acquire($sessionId);
    }

    public function tryAcquire(Uuid $sessionId): bool
    {
        return $this->lock->tryAcquire($sessionId);
    }

    public function release(Uuid $sessionId): void
    {
        $this->lock->release($sessionId);
    }
}
