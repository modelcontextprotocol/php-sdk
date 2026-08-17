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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Task;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;

/**
 * A task store held in one process's memory.
 *
 * Correct for stdio and for persistent runtimes where the whole server is one
 * process. Under PHP-FPM it is not: the worker that creates a task and the
 * worker polled for it are different processes, and every `tasks/get` would
 * answer "no such task". {@see Psr16TaskStore} is the answer there.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InMemoryTaskStore implements TaskStoreInterface
{
    /** @var array<string, Task> */
    private array $tasks = [];

    private readonly ClockInterface $clock;

    /**
     * @param int $limit how many tasks to keep before dropping the oldest, so an unbounded
     *                   run of task creation cannot exhaust memory
     */
    public function __construct(
        private readonly int $limit = 1000,
        ?ClockInterface $clock = null,
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException(\sprintf('A task store must hold at least one task, got %d.', $this->limit));
        }

        $this->clock = $clock ?? new NativeClock();
    }

    public function save(Task $task): void
    {
        // Re-inserted rather than updated in place, so the eviction order below
        // reflects last use and a task being polled is not the one dropped.
        unset($this->tasks[$task->taskId]);
        $this->tasks[$task->taskId] = $task;

        while (\count($this->tasks) > $this->limit) {
            array_shift($this->tasks);
        }
    }

    public function get(string $taskId): ?Task
    {
        $task = $this->tasks[$taskId] ?? null;

        if (null === $task) {
            return null;
        }

        if (!$task->isReadable($this->clock->now())) {
            unset($this->tasks[$taskId]);

            return null;
        }

        return $task;
    }

    public function delete(string $taskId): void
    {
        unset($this->tasks[$taskId]);
    }
}
