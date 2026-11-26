<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Task;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Server\Exception\RuntimeException;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * A task store over any PSR-16 cache, so a task created in one process can be
 * polled from another.
 *
 * The cache's own TTL is set from the task's `ttlMs`, so an expired task
 * disappears without anything having to sweep for it. Stored as JSON rather
 * than with PHP's serializer: the payload is written by one process and read
 * by another, possibly after a deploy.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Psr16TaskStore implements TaskStoreInterface
{
    /**
     * @param string $prefix namespace for this store's keys, so one cache can carry several
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp.tasks.',
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @throws RuntimeException when the cache did not keep the task, so no handle to it is handed out
     */
    public function save(Task $task): void
    {
        $ttl = null === $task->ttlMs ? null : max(1, (int) ceil($task->ttlMs / 1000));

        // The owner is never sent on the wire, but must survive the round trip.
        $payload = [...$task->jsonSerialize(), 'owner' => $task->owner];

        if (!$this->cache->set($this->prefix.$task->taskId, json_encode($payload, \JSON_THROW_ON_ERROR), $ttl)) {
            throw new RuntimeException(\sprintf('The cache refused to store task "%s".', $task->taskId));
        }
    }

    public function get(string $taskId): ?Task
    {
        $raw = $this->cache->get($this->prefix.$taskId);

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            $task = Task::fromArray(\is_array($data) ? $data : []);
        } catch (\JsonException|InvalidArgumentException $e) {
            // Written by an older shape, or corrupted: to a client, the same as expired.
            $this->logger->warning('Dropped an unreadable task from the store.', ['taskId' => $taskId, 'exception' => $e]);

            return null;
        }

        // The cache TTL is whole seconds, so the task has the last word on whether it is readable.
        return $task->isReadable($this->clock->now()) ? $task : null;
    }
}
