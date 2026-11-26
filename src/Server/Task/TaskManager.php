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

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Exception\LogicException as SchemaLogicException;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Exception\InvalidArgumentException;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Mcp\Server\NativeClock;
use Mcp\Server\RequestContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates tasks for a tool handler, and lets the application advance them.
 *
 * Holds no request state: the request is passed to each call, so one manager
 * serves every request of a long-running worker.
 *
 * @experimental
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class TaskManager
{
    public function __construct(
        private readonly TaskStoreInterface $store,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Whether a task can be handed to the client of this request: it declared
     * the extension, on a revision the extension exists in.
     */
    public function isSupported(RequestContext $context): bool
    {
        return $context->getProtocolVersion()->isAtLeast(ProtocolVersion::V2026_07_28)
            && $context->getClientGateway()->supportsExtension(TasksExtension::ID);
    }

    /**
     * Stores a new task for this `tools/call` and returns the result to answer
     * it with. The task is stored before its id is handed out, so the first
     * `tasks/get` cannot arrive before it exists.
     *
     * @param ?int $ttlMs          how long the task stays readable; null for no limit
     * @param ?int $pollIntervalMs how long the client should wait between polls
     *
     * @throws MissingRequiredClientCapabilityException when the client cannot poll, answered as -32021
     * @throws LogicException                           when the request is not a `tools/call`
     */
    public function create(RequestContext $context, ?int $ttlMs = 3_600_000, ?int $pollIntervalMs = 1000, ?string $statusMessage = null): CreateTaskResult
    {
        if (!$context->getRequest() instanceof CallToolRequest) {
            throw new LogicException(\sprintf('Only "tools/call" can be answered with a task, not "%s".', $context->getRequest()::getMethod()));
        }

        $this->requireSupport($context);

        $now = $this->clock->now();
        $task = new Task(Uuid::v4()->toRfc4122(), TaskStatus::Working, $now, $now, $ttlMs, $pollIntervalMs, $statusMessage, owner: self::ownerOf($context));

        $this->store->save($task);

        return new CreateTaskResult($task);
    }

    /**
     * The task, for the client of this request only: null when there is no such
     * task or when someone else created it, which the client cannot tell apart.
     *
     * @throws MissingRequiredClientCapabilityException when the client did not declare the extension
     */
    public function find(RequestContext $context, string $taskId): ?Task
    {
        $this->requireSupport($context);

        $task = $this->store->get($taskId);

        if (null === $task || (null !== $task->owner && $task->owner !== self::ownerOf($context))) {
            return null;
        }

        return $task;
    }

    /**
     * The task as stored, for the application running it.
     */
    public function get(string $taskId): ?Task
    {
        return $this->store->get($taskId);
    }

    /**
     * Moves a task on, for the application running it. Read back from the
     * store first, so a cancellation recorded meanwhile is not overwritten.
     *
     * @param ?array<string, mixed> $result the `CallToolResult` to inline once completed
     *
     * @throws InvalidArgumentException when there is no such task, or it expired
     * @throws SchemaLogicException     when the task already reached a terminal status
     */
    public function transition(string $taskId, TaskStatus $status, ?array $result = null, ?Error $error = null, ?string $statusMessage = null): Task
    {
        $task = $this->store->get($taskId) ?? throw new InvalidArgumentException(\sprintf('Unknown task "%s".', $taskId));
        $task = $task->with($status, $this->clock->now(), $result, $error, $statusMessage);

        $this->store->save($task);

        return $task;
    }

    /**
     * Records a cancellation; a task already in a terminal status is left as it is.
     */
    public function cancel(Task $task): void
    {
        if (!$task->status->isTerminal()) {
            $this->store->save($task->with(TaskStatus::Cancelled, $this->clock->now()));
        }
    }

    private function requireSupport(RequestContext $context): void
    {
        if (!$this->isSupported($context)) {
            throw new MissingRequiredClientCapabilityException(new ClientCapabilities(extensions: [TasksExtension::ID => new \stdClass()]), \sprintf('Tasks need the "%s" extension, which the client did not declare.', TasksExtension::ID));
        }
    }

    /**
     * The OAuth client and subject the request was authorized for; null when it
     * was not, and the unguessable task id is then the only credential.
     */
    private static function ownerOf(RequestContext $context): ?string
    {
        $token = $context->getAccessToken();

        return null === $token ? null : json_encode([$token->getClientId(), $token->getSubject()], \JSON_THROW_ON_ERROR);
    }
}
