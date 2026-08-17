<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\ResultType;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Task;

/**
 * The answer to a request the server decided to run as a task (SEP-2663).
 *
 * The result *is* the task: `resultType: "task"` alongside the task's own
 * fields, at the root. A nested wrapper would make a client unpack one shape
 * here and another from `tasks/get`, for no gain.
 *
 * Whether to create a task is the server's decision, not something the client
 * asks for per request — but the server must not make it for a client that did
 * not declare the extension, which {@see \Mcp\Server\Task\TasksExtension} is
 * what checks.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class CreateTaskResult implements ResultInterface
{
    public function __construct(
        public readonly Task $task,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the data is not a task handle
     */
    public static function fromArray(array $data): self
    {
        if (ResultType::Task->value !== ($data['resultType'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid "resultType" in CreateTaskResult data.');
        }

        return new self(Task::fromArray($data));
    }

    /**
     * Whether a result is a task handle rather than the answer itself.
     *
     * @param array<string, mixed> $data
     */
    public static function describes(array $data): bool
    {
        return ResultType::Task->value === ($data['resultType'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        // The envelope only: a task that was just created has no result, no
        // error and nothing it is waiting for.
        return ['resultType' => ResultType::Task->value, ...$this->task->toEnvelope()];
    }
}
