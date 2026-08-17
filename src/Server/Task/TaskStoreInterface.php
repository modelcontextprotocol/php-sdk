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
 * Where tasks live between the request that created one and the polls that read
 * it (SEP-2663).
 *
 * A task ID is a *durable* handle — the point of the extension is that it
 * survives a dropped connection, a client restart, and in PHP's case the death
 * of the process that created it. So the store is an interface rather than a
 * property of the protocol object: which implementation is right depends
 * entirely on whether the process that creates a task is the process that will
 * be polled for it. Under PHP-FPM it is not.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface TaskStoreInterface
{
    /**
     * Stores a task, overwriting any earlier state under the same id.
     */
    public function save(Task $task): void;

    /**
     * The task, or null when there is no such id — or when its TTL has lapsed,
     * which a client cannot tell apart and does not need to.
     */
    public function get(string $taskId): ?Task;

    /**
     * Drops a task before its TTL, if the store supports it.
     */
    public function delete(string $taskId): void;
}
