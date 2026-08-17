<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Task;

use Mcp\Schema\Task;

/**
 * What an application does with the input a waiting task asked for.
 *
 * Separate from the store because storing a task and *running* one are
 * different jobs: the SDK owns the first and knows nothing about the second.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface TaskInputHandlerInterface
{
    /**
     * Applies $inputResponses to $task and returns its new state.
     *
     * Keys match the task's `inputRequests`. Anything unrecognized should be
     * ignored rather than refused.
     *
     * @param array<string, mixed> $inputResponses
     */
    public function receive(Task $task, array $inputResponses): Task;
}
