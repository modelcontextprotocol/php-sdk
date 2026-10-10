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

use Mcp\Exception\SessionLockException;
use Mcp\Server\Session\SessionLockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A session lock that records its calls, next to the store's, in one shared log.
 */
final class RecordingSessionLock implements SessionLockInterface
{
    /** @var array<string, true> */
    private array $held = [];

    /**
     * @param list<string> $log        receives "acquire <id>" and "release <id>" entries
     * @param bool         $acquirable false makes every acquire() fail, like a lock another worker holds past the timeout
     */
    public function __construct(
        public array &$log,
        private readonly bool $acquirable = true,
    ) {
    }

    public function acquire(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        if (!$this->acquirable) {
            throw new SessionLockException(\sprintf('Timed out waiting for the lock of session "%s".', $key));
        }

        if (isset($this->held[$key])) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is already held.', $key));
        }

        $this->held[$key] = true;
        $this->log[] = 'acquire '.$key;
    }

    public function release(Uuid $sessionId): void
    {
        $key = $sessionId->toRfc4122();

        if (!isset($this->held[$key])) {
            throw new SessionLockException(\sprintf('The lock of session "%s" is not held.', $key));
        }

        unset($this->held[$key]);
        $this->log[] = 'release '.$key;
    }

    /**
     * @return list<string>
     */
    public function heldSessions(): array
    {
        return array_keys($this->held);
    }
}
