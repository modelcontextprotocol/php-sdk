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

use Mcp\Exception\TimeoutException;
use Mcp\Server\Session\SessionLockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A session lock for requests replayed in one process, each in its own fiber.
 *
 * A request that finds the session locked suspends its fiber where a worker would
 * block, and is resumed by the release of the request holding the lock.
 */
final class FiberSessionLock implements SessionLockInterface
{
    /** @var array<string, true> */
    private array $held = [];

    /** @var array<string, list<\Fiber<mixed, mixed, mixed, mixed>>> */
    private array $waiting = [];

    /** @var list<string> */
    public array $log = [];

    public function acquire(Uuid $id): void
    {
        $key = $id->toRfc4122();

        while (isset($this->held[$key])) {
            if (null === $fiber = \Fiber::getCurrent()) {
                throw new TimeoutException('The session is locked and the caller cannot wait.');
            }

            $this->waiting[$key][] = $fiber;
            \Fiber::suspend();
        }

        $this->held[$key] = true;
        $this->log[] = 'acquire';
    }

    public function release(Uuid $id): void
    {
        $key = $id->toRfc4122();
        unset($this->held[$key]);
        $this->log[] = 'release';

        if ([] !== ($this->waiting[$key] ?? [])) {
            array_shift($this->waiting[$key])->resume();
        }
    }

    public function isLocked(Uuid $id): bool
    {
        return isset($this->held[$id->toRfc4122()]);
    }
}
