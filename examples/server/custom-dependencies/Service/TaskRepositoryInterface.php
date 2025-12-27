<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\CustomDependencies\Service;

/**
 * @phpstan-type Task array{id: int, userId: string, description: string, completed: bool, createdAt: string}
 */
interface TaskRepositoryInterface
{
    /**
     * @return Task
     */
    public function addTask(string $userId, string $description): array;

    /**
     * @return Task[]
     */
    public function getTasksForUser(string $userId): array;

    /**
     * @return Task[]
     */
    public function getAllTasks(): array;

    public function completeTask(int $taskId): bool;
}
