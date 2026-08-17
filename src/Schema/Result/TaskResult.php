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

use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Task;

/**
 * The answer to `tasks/get`: the task, in detail (SEP-2663).
 *
 * Flat like {@see CreateTaskResult}, and additionally carrying whatever the
 * current status implies — the inlined `result` once completed, the `error`
 * once failed, the `inputRequests` while waiting. There is no separate
 * `tasks/result` method to fetch the first of those.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class TaskResult implements ResultInterface
{
    public function __construct(
        public readonly Task $task,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Task::fromArray($data));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->task->jsonSerialize();
    }
}
