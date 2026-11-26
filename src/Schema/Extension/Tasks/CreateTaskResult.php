<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Tasks;

use Mcp\Schema\Enum\ResultType;
use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The answer to a `tools/call` the server decided to run as a task (SEP-2663).
 *
 * The result *is* the task: `resultType: "task"` alongside the task's own
 * fields, at the root.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CreateTaskResult implements ResultInterface
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
        if (!self::describes($data)) {
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
        return ['resultType' => ResultType::Task->value, ...$this->task->toEnvelope()];
    }
}
