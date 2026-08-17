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

use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Task;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * A task store over any PSR-16 cache, so a task created in one process can be
 * polled from another.
 *
 * This is the implementation PHP-FPM needs — and the reason the store is an
 * interface at all. The cache's own TTL is set from the task's `ttlMs`, so an
 * expired task disappears without anything having to sweep for it.
 *
 * Serialized as JSON rather than with PHP's serializer: the payload is written
 * by one process and read by another, possibly after a deploy, and a JSON
 * document survives a class definition changing shape in a way a serialized
 * object does not.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Psr16TaskStore implements TaskStoreInterface
{
    private readonly ClockInterface $clock;

    /**
     * @param string $prefix     namespace for this store's keys, so one cache can carry several
     * @param int    $defaultTtl seconds a task with no `ttlMs` of its own is kept
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp.tasks.',
        private readonly int $defaultTtl = 3600,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new NativeClock();
    }

    public function save(Task $task): void
    {
        $ttl = null !== $task->ttlMs
            ? max(1, (int) ceil($task->ttlMs / 1000))
            : $this->defaultTtl;

        $this->cache->set($this->prefix.$task->taskId, json_encode(self::toArray($task), \JSON_THROW_ON_ERROR), $ttl);
    }

    public function get(string $taskId): ?Task
    {
        $raw = $this->cache->get($this->prefix.$taskId);

        if (!\is_string($raw)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
            $task = Task::fromArray($data);
        } catch (\Throwable $e) {
            // Written by an older shape, or corrupted. Unreadable and expired
            // look the same to a client, and neither is worth failing on.
            $this->logger->warning('Dropped an unreadable task from the store.', ['taskId' => $taskId, 'exception' => $e]);

            return null;
        }

        // The cache TTL is coarser than the task's own — it is whole seconds —
        // so the task still has the last word on whether it is readable.
        return $task->isReadable($this->clock->now()) ? $task : null;
    }

    public function delete(string $taskId): void
    {
        $this->cache->delete($this->prefix.$taskId);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(Task $task): array
    {
        return [
            'taskId' => $task->taskId,
            'status' => $task->status->value,
            'createdAt' => $task->createdAt?->format(\DATE_ATOM),
            'lastUpdatedAt' => $task->lastUpdatedAt?->format(\DATE_ATOM),
            'ttlMs' => $task->ttlMs,
            'pollIntervalMs' => $task->pollIntervalMs,
            'statusMessage' => $task->statusMessage,
            'result' => $task->result,
            'error' => null !== $task->error
                ? ['code' => $task->error->code, 'message' => $task->error->message, 'data' => $task->error->data]
                : null,
            // Kept as the envelopes they go out as, so a parked task still
            // knows what it is waiting for after a round trip through storage.
            'inputRequests' => array_map(
                static fn (Request $request): array => $request->withId(0)->jsonSerialize(),
                $task->inputRequests,
            ),
        ];
    }
}
