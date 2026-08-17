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
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TasksGetRequest extends Request
{
    public function __construct(
        public readonly string $taskId,
    ) {
    }

    public static function getMethod(): string
    {
        return 'tasks/get';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['taskId']) || !\is_string($params['taskId']) || '' === $params['taskId']) {
            throw new InvalidArgumentException('Missing or invalid "taskId" parameter for tasks/get.');
        }

        return new self($params['taskId']);
    }

    /**
     * @return array{taskId: string}
     */
    protected function getParams(): array
    {
        return ['taskId' => $this->taskId];
    }
}
