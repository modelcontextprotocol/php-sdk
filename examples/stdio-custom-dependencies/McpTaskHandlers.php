<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioCustomDependencies;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Example\DependenciesStdioExample\Service\StatsServiceInterface;
use Mcp\Example\DependenciesStdioExample\Service\TaskRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * @phpstan-import-type Task from TaskRepositoryInterface
 */
final class McpTaskHandlers
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepo,
        private readonly StatsServiceInterface $statsService,
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('McpTaskHandlers instantiated with dependencies.');
    }

    /**
     * Adds a new task for a given user.
     *
     * @param string $userId      the ID of the user
     * @param string $description the task description
     *
     * @return Task the created task details
     */
    #[McpTool(name: 'add_task')]
    public function addTask(string $userId, string $description): array
    {
        $this->logger->info("Tool 'add_task' invoked", ['userId' => $userId]);

        return $this->taskRepo->addTask($userId, $description);
    }

    /**
     * Lists pending tasks for a specific user.
     *
     * @param string $userId the ID of the user
     *
     * @return Task[] a list of tasks
     */
    #[McpTool(name: 'list_user_tasks')]
    public function listUserTasks(string $userId): array
    {
        $this->logger->info("Tool 'list_user_tasks' invoked", ['userId' => $userId]);

        return $this->taskRepo->getTasksForUser($userId);
    }

    /**
     * Marks a task as complete.
     *
     * @param int $taskId the ID of the task to complete
     *
     * @return array<string, mixed> status of the operation
     */
    #[McpTool(name: 'complete_task')]
    public function completeTask(int $taskId): array
    {
        $this->logger->info("Tool 'complete_task' invoked", ['taskId' => $taskId]);
        $success = $this->taskRepo->completeTask($taskId);

        return ['success' => $success, 'message' => $success ? "Task {$taskId} completed." : "Task {$taskId} not found."];
    }

    /**
     * Provides current system statistics.
     *
     * @return array<string, int> system statistics
     */
    #[McpResource(uri: 'stats://system/overview', name: 'system_stats', mimeType: 'application/json')]
    public function getSystemStatistics(): array
    {
        $this->logger->info("Resource 'stats://system/overview' invoked");

        return $this->statsService->getSystemStats();
    }
}
