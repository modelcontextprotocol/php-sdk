<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Server for Mcp\Tests\Integration\TasksTest.
 *
 * A stdio server is one process, so there is no worker to advance a task
 * between polls. The store below stands in for one: each task carries the
 * states it will move through, and every read advances it by one.
 */

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\RequestContext;
use Mcp\Server\Server;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\TaskManager;
use Mcp\Server\Task\TasksExtension;
use Mcp\Server\Task\TaskStoreInterface;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$store = new class(new InMemoryTaskStore()) implements TaskStoreInterface {
    /** @var array<string, list<callable(Task): Task>> what each task becomes on its next reads */
    private array $plans = [];

    public function __construct(private readonly TaskStoreInterface $inner)
    {
    }

    /**
     * @param list<callable(Task): Task> $steps
     */
    public function plan(string $taskId, array $steps): void
    {
        $this->plans[$taskId] = $steps;
    }

    public function save(Task $task): void
    {
        $this->inner->save($task);
    }

    public function get(string $taskId): ?Task
    {
        $task = $this->inner->get($taskId);

        if (null === $task || $task->status->isTerminal() || [] === ($this->plans[$taskId] ?? [])) {
            return $task;
        }

        $next = array_shift($this->plans[$taskId])($task);
        $this->inner->save($next);

        return $next;
    }
};

$tasks = new TaskManager($store);

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->enableExtension(new TasksExtension($tasks))
    ->addTool(
        static function (RequestContext $context) use ($tasks, $store): CreateTaskResult|string {
            if (!$tasks->isSupported($context)) {
                return 'sync';
            }

            $created = $tasks->create($context, pollIntervalMs: 10, statusMessage: 'queued');
            $store->plan($created->task->taskId, [
                static fn (Task $task): Task => $task->with(TaskStatus::Working, new DateTimeImmutable(), statusMessage: 'halfway'),
                static fn (Task $task): Task => $task->with(TaskStatus::Completed, new DateTimeImmutable(), result: (new CallToolResult([new TextContent('done')]))->jsonSerialize()),
            ]);

            return $created;
        },
        name: 'long_job',
        description: 'Runs as a task when the client can poll, synchronously otherwise.',
    )
    ->addTool(
        static fn (RequestContext $context): CreateTaskResult => $tasks->create($context, pollIntervalMs: 10),
        name: 'task_only',
        description: 'Always creates a task, even for a client that cannot poll.',
    )
    ->addTool(
        static function (RequestContext $context) use ($tasks, $store): CreateTaskResult {
            $created = $tasks->create($context, pollIntervalMs: 10);
            $store->plan($created->task->taskId, [
                static fn (Task $task): Task => $task->with(TaskStatus::Failed, new DateTimeImmutable(), error: Error::forInternalError('the worker died')),
            ]);

            return $created;
        },
        name: 'failing_job',
        description: 'Fails at the protocol level.',
    )
    ->build()
    ->run(new StdioTransport());
