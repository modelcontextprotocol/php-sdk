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
     * @var array<string, array{ data: string, timestamp: int }>
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

    public function read(Uuid $id): string|false
    {
        $session = $this->store[$id->toRfc4122()] ?? '';
        if ('' === $session) {
            return false;
        }

        $currentTimestamp = $this->clock->now()->getTimestamp();

        if ($currentTimestamp - $session['timestamp'] > $this->ttl) {
            unset($this->store[$id->toRfc4122()]);

            return false;
        }

        return $session['data'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->store[$id->toRfc4122()] = [
            'data' => $data,
            'timestamp' => $this->clock->now()->getTimestamp(),
        ];

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        if (isset($this->store[$id->toRfc4122()])) {
            unset($this->store[$id->toRfc4122()]);
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
