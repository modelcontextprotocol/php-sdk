<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

/**
 * Supplies the input a task asked for while it was `input_required`.
 *
 * The answers are keyed as the task's `inputRequests` were. There is no state
 * to echo back — the task itself is the state, and it lives server-side.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksUpdateRequest extends Request
{
    /**
     * @param array<string, mixed> $inputResponses client answers, keyed as the task's inputRequests were
     */
    public function __construct(
        public readonly string $taskId,
        public readonly array $inputResponses = [],
    ) {
    }

    public static function getMethod(): string
    {
        return 'tasks/update';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['taskId']) || !\is_string($params['taskId']) || '' === $params['taskId']) {
            throw new InvalidArgumentException('Missing or invalid "taskId" parameter for tasks/update.');
        }

        $responses = $params['inputResponses'] ?? [];

        if (!\is_array($responses)) {
            throw new InvalidArgumentException('Invalid "inputResponses" parameter for tasks/update.');
        }

        return new self($params['taskId'], $responses);
    }

    /**
     * @return array{taskId: string, inputResponses: array<string, mixed>}
     */
    protected function getParams(): array
    {
        return ['taskId' => $this->taskId, 'inputResponses' => $this->inputResponses];
    }
}
