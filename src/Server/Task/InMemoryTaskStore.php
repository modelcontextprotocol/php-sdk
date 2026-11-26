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

use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Server\Exception\InvalidArgumentException;
use Mcp\Server\NativeClock;
use Psr\Clock\ClockInterface;

/**
 * A task store held in one process's memory.
 *
 * Correct for stdio and for persistent runtimes where the whole server is one
 * process. Under PHP-FPM it is not: the worker that creates a task and the
 * worker polled for it are different processes. {@see Psr16TaskStore} is the
 * answer there.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InMemoryTaskStore implements TaskStoreInterface
{
    /** @var array<string, Task> */
    private array $tasks = [];

    /**
     * @param int $limit how many tasks to keep before dropping the least recently saved one, so an
     *                   unbounded run of task creation cannot exhaust memory
     */
    public function __construct(
        private readonly int $limit = 1000,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
        if ($this->limit < 1) {
            throw new InvalidArgumentException(\sprintf('A task store must hold at least one task, got %d.', $this->limit));
        }
    }

    public function save(Task $task): void
    {
        // Re-inserted rather than updated in place, so the first key is always
        // the least recently saved task.
        unset($this->tasks[$task->taskId]);
        $this->tasks[$task->taskId] = $task;

        if (\count($this->tasks) > $this->limit) {
            unset($this->tasks[array_key_first($this->tasks)]);
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
}
