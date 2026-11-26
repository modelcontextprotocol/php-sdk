<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Task;

use Mcp\Client\Client;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TasksCancelRequest;
use Mcp\Schema\Extension\Tasks\TasksGetRequest;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;

/**
 * The client side of the Tasks extension (SEP-2663), on top of a connected
 * {@see Client} that declared it.
 *
 * @experimental
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TaskClient
{
    public function __construct(
        private readonly Client $client,
    ) {
    }

    /**
     * Calls a tool, which the server may answer with a task to poll instead of its result.
     *
     * @param array<string, mixed>                                                    $arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function callTool(string $name, array $arguments = [], ?callable $onProgress = null): CallToolResult|CreateTaskResult
    {
        $result = $this->client->sendRequest(new CallToolRequest($name, $arguments), $onProgress)->result;

        return CreateTaskResult::describes($result) ? CreateTaskResult::fromArray($result) : CallToolResult::fromArray($result);
    }

    /**
     * The current state of a task (`tasks/get`). Poll it at its `pollIntervalMs`
     * until its status is terminal; a completed task carries the tool result.
     */
    public function get(string $taskId): Task
    {
        return Task::fromArray($this->client->sendRequest(new TasksGetRequest($taskId))->result);
    }

    /**
     * Asks the server to cancel a task (`tasks/cancel`). Cooperative: the task may still finish.
     */
    public function cancel(string $taskId): void
    {
        $this->client->sendRequest(new TasksCancelRequest($taskId));
    }
}
