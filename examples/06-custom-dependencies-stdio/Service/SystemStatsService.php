<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Example\DependenciesStdioExample\Service;

class SystemStatsService implements StatsServiceInterface
{
    public function __construct(private readonly TaskRepositoryInterface $taskRepository)
    {
    }

    public function getSystemStats(): array
    {
        $allTasks = $this->taskRepository->getAllTasks();
        $completed = \count(array_filter($allTasks, fn (array $task) => $task['completed']));
        $pending = \count($allTasks) - $completed;

        return [
            'total_tasks' => \count($allTasks),
            'completed_tasks' => $completed,
            'pending_tasks' => $pending,
            'server_uptime_seconds' => time() - $_SERVER['REQUEST_TIME_FLOAT'], // Approx uptime for CLI script
        ];
    }
}
