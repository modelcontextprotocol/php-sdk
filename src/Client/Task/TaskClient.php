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

use Mcp\Client;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Request\TasksCancelRequest;
use Mcp\Schema\Request\TasksGetRequest;
use Mcp\Schema\Request\TasksUpdateRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ReadResourceResult;
use Mcp\Schema\Result\TaskResult;
use Mcp\Schema\Task;

/**
 * The client side of the Tasks extension (SEP-2663), on top of a connected
 * {@see Client} that declared it.
 *
 * ```php
 * $client = Client::builder()->enableExtension(new TasksExtension())->build();
 * $client->connect($transport);
 *
 * $tasks = new TaskClient($client);
 * $result = $tasks->callTool('long_job');
 *
 * if ($result instanceof CreateTaskResult) {
 *     do {
 *         usleep(1000 * ($result->task->pollIntervalMs ?? 1000));
 *         $task = $tasks->get($result->task->taskId);
 *     } while (!$task->status->isTerminal());
 * }
 * ```
 *
 * The core client's `callTool()`, `getPrompt()` and `readResource()` expect
 * the answer itself; these variants accept a task handle in its place, which a
 * server may send once the extension is declared.
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
     * @param array<string, mixed>                                                    $arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function callTool(string $name, array $arguments = [], ?callable $onProgress = null): CallToolResult|CreateTaskResult
    {
        $result = $this->client->request(new CallToolRequest($name, $arguments), $onProgress)->result;

        return CreateTaskResult::describes($result) ? CreateTaskResult::fromArray($result) : CallToolResult::fromArray($result);
    }

    /**
     * @param array<string, string>                                                   $arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function getPrompt(string $name, array $arguments = [], ?callable $onProgress = null): GetPromptResult|CreateTaskResult
    {
        $result = $this->client->request(new GetPromptRequest($name, $arguments), $onProgress)->result;

        return CreateTaskResult::describes($result) ? CreateTaskResult::fromArray($result) : GetPromptResult::fromArray($result);
    }

    /**
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function readResource(string $uri, ?callable $onProgress = null): ReadResourceResult|CreateTaskResult
    {
        $result = $this->client->request(new ReadResourceRequest($uri), $onProgress)->result;

        return CreateTaskResult::describes($result) ? CreateTaskResult::fromArray($result) : ReadResourceResult::fromArray($result);
    }

    /**
     * The current state of a task (`tasks/get`).
     *
     * Poll it at the task's `pollIntervalMs` until {@see \Mcp\Schema\Enum\TaskStatus::isTerminal()};
     * a completed task carries the original request's result, an
     * `input_required` one what it is waiting for, to answer with {@see self::update()}.
     */
    public function get(string $taskId): Task
    {
        return TaskResult::fromArray($this->client->request(new TasksGetRequest($taskId))->result)->task;
    }

    /**
     * Answers what a task asked for (`tasks/update`).
     *
     * @param array<string, mixed> $inputResponses keyed as the task's `inputRequests` were
     */
    public function update(string $taskId, array $inputResponses): void
    {
        $this->client->request(new TasksUpdateRequest($taskId, $inputResponses));
    }

    /**
     * Asks the server to cancel a task (`tasks/cancel`). Cooperative: the task
     * may still finish.
     */
    public function cancel(string $taskId): void
    {
        $this->client->request(new TasksCancelRequest($taskId));
    }
}
