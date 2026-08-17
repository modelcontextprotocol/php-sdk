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

use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Schema\Task;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What the Tasks extension hands a tool, prompt or resource handler.
 *
 * Declare a `TaskContext` parameter and it arrives for the request being
 * served, the way a `RequestContext` does — the extension provides it, so the
 * core context does not have to know about tasks.
 *
 * ```php
 * static function (TaskContext $tasks): CreateTaskResult|string {
 *     if (!$tasks->isSupported()) {
 *         return runSynchronously();
 *     }
 *
 *     $created = $tasks->create(pollIntervalMs: 1000);
 *     $queue->push($created->task->taskId);
 *
 *     return $created;
 * }
 * ```
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TaskContext
{
    public function __construct(
        private readonly SessionInterface $session,
        private readonly TaskStoreInterface $store,
    ) {
    }

    /**
     * Whether this client declared the extension during `initialize`.
     *
     * A handler that can work either way must ask before creating a task: a
     * client that did not declare it has no polling loop, and the specification
     * says such a request falls through to running synchronously rather than
     * failing.
     */
    public function isSupported(): bool
    {
        return TaskCapabilityGuard::declared($this->session);
    }

    /**
     * Creates a task and stores it, returning the result to hand back.
     *
     * The handler's own work is not started here — that is the application's,
     * and in PHP it usually means enqueueing something a worker picks up. What
     * this guarantees is the part the specification cares about: the task is
     * durably stored *before* its id reaches the client, so the first
     * `tasks/get` cannot arrive before the task exists.
     *
     * @param ?int $ttlMs          how long the task stays readable; null for no limit
     * @param ?int $pollIntervalMs how long the client should wait between polls
     *
     * @throws MissingRequiredClientCapabilityException when the client did not declare the extension — it has no
     *                                                  `tasks/get` loop, and a handle it will never poll is worse
     *                                                  than a slow answer; the server answers `-32021` instead
     */
    public function create(
        ?string $taskId = null,
        ?int $ttlMs = 3_600_000,
        ?int $pollIntervalMs = 1000,
        ?string $statusMessage = null,
    ): CreateTaskResult {
        if (!$this->isSupported()) {
            throw new MissingRequiredClientCapabilityException(new ClientCapabilities(roots: false, extensions: [TasksExtension::ID => new \stdClass()]), \sprintf('This request is served as a task, which needs the "%s" extension the client did not declare.', TasksExtension::ID));
        }

        $now = new \DateTimeImmutable();

        $task = new Task(
            $taskId ?? Uuid::v4()->toRfc4122(),
            TaskStatus::Working,
            $now,
            $now,
            $ttlMs,
            $pollIntervalMs,
            $statusMessage,
        );

        $this->store->save($task);

        return new CreateTaskResult($task);
    }

    /**
     * The store this request is served with, for a handler that needs to
     * advance a task it did not create here.
     */
    public function getStore(): TaskStoreInterface
    {
        return $this->store;
    }
}
