<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\CustomDependencies\Service;

use Psr\Log\LoggerInterface;

/**
 * @phpstan-import-type Task from TaskRepositoryInterface
 */
final class InMemoryTaskRepository implements TaskRepositoryInterface
{
    /**
     * @var array<int, Task>
     */
    private array $tasks = [];
    private int $nextTaskId = 1;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        // Add some initial tasks
        $this->addTask('user1', 'Buy groceries');
        $this->addTask('user1', 'Write MCP example');
        $this->addTask('user2', 'Review PR');
    }

    public function addTask(string $userId, string $description): array
    {
        $task = [
            'id' => $this->nextTaskId++,
            'userId' => $userId,
            'description' => $description,
            'completed' => false,
            'createdAt' => date('c'),
        ];
        $this->tasks[$task['id']] = $task;
        $this->logger->info('Task added', ['id' => $task['id'], 'user' => $userId]);

        return $task;
    }

    public function getTasksForUser(string $userId): array
    {
        return array_values(array_filter($this->tasks, fn ($task) => $task['userId'] === $userId && !$task['completed']));
    }

    public function getAllTasks(): array
    {
        return array_values($this->tasks);
    }

    public function completeTask(int $taskId): bool
    {
        if (isset($this->tasks[$taskId])) {
            $this->tasks[$taskId]['completed'] = true;
            $this->logger->info('Task completed', ['id' => $taskId]);

            return true;
        }

        return false;
    }
}
