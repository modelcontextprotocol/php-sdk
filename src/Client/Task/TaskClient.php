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

use Mcp\Client\CancellationTokenInterface;
use Mcp\Client\Client;
use Mcp\Client\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Tasks\CancelTaskRequest;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\GetTaskRequest;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TasksExtension;
use Mcp\Schema\Extension\Tasks\UpdateTaskRequest;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ReadResourceResult;

/**
 * Client facade for a connection configured with {@see TasksExtension}.
 *
 * @author OpenAI
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
    public function callTool(string $name, array $arguments = [], ?callable $onProgress = null, ?CancellationTokenInterface $cancellation = null, ?float $timeoutSeconds = null): CallToolResult|CreateTaskResult
    {
        $result = $this->client->request(new CallToolRequest($name, $arguments), $onProgress, $cancellation, $timeoutSeconds)->result;

        return CreateTaskResult::RESULT_TYPE === ($result['resultType'] ?? null)
            ? CreateTaskResult::fromArray($result)
            : CallToolResult::fromArray($result);
    }

    /**
     * @param array<string, string>                                                   $arguments
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function getPrompt(string $name, array $arguments = [], ?callable $onProgress = null): GetPromptResult|CreateTaskResult
    {
        $result = $this->client->request(new GetPromptRequest($name, $arguments), $onProgress)->result;

        return CreateTaskResult::RESULT_TYPE === ($result['resultType'] ?? null)
            ? CreateTaskResult::fromArray($result)
            : GetPromptResult::fromArray($result);
    }

    /**
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function readResource(string $uri, ?callable $onProgress = null): ReadResourceResult|CreateTaskResult
    {
        if ('' === $uri) {
            throw new InvalidArgumentException('Resource URI must not be empty.');
        }

        $result = $this->client->request(new ReadResourceRequest($uri), $onProgress)->result;

        return CreateTaskResult::RESULT_TYPE === ($result['resultType'] ?? null)
            ? CreateTaskResult::fromArray($result)
            : ReadResourceResult::fromArray($result);
    }

    public function get(string $taskId): Task
    {
        return Task::fromArray($this->client->request(new GetTaskRequest($taskId))->result);
    }

    /**
     * @param array<array-key, array<string, mixed>|ResultInterface> $inputResponses responses keyed by the server's input request IDs
     */
    public function update(string $taskId, array $inputResponses): void
    {
        $this->client->request(new UpdateTaskRequest($taskId, $inputResponses));
    }

    public function cancel(string $taskId): void
    {
        $this->client->request(new CancelTaskRequest($taskId));
    }
}
