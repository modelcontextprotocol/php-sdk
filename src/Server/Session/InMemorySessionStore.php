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

class InMemorySessionStore implements SessionStoreInterface
{
    /**
     * @var array<string, array{ data: array, timestamp: int }>
     */
    protected array $store = [];

    public function __construct(
        protected readonly int $ttl = 3600,
        protected readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return isset($this->store[$id->toRfc4122()]);
    }

    public function read(Uuid $sessionId): string|false
    {
        $session = $this->store[$sessionId->toRfc4122()] ?? '';
        if ('' === $session) {
            return false;
        }

        $currentTimestamp = $this->clock->now()->getTimestamp();

        if ($currentTimestamp - $session['timestamp'] > $this->ttl) {
            unset($this->store[$sessionId]);

            return false;
        }

        return $session['data'];
    }

    public function write(Uuid $sessionId, string $data): bool
    {
        $this->store[$sessionId->toRfc4122()] = [
            'data' => $data,
            'timestamp' => $this->clock->now()->getTimestamp(),
        ];

        return true;
    }

    public function destroy(Uuid $sessionId): bool
    {
        if (isset($this->store[$sessionId->toRfc4122()])) {
            unset($this->store[$sessionId]);
        }

        return true;
    }

    public function gc(): array
    {
        $currentTimestamp = $this->clock->now()->getTimestamp();
        $deletedSessions = [];

        foreach ($this->store as $sessionId => $session) {
            $sessionId = Uuid::fromString($sessionId);
            if ($currentTimestamp - $session['timestamp'] > $this->ttl) {
                unset($this->store[$sessionId->toRfc4122()]);
                $deletedSessions[] = $sessionId;
            }
        }

        return $deletedSessions;
    }
}
