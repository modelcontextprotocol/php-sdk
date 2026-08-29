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
 * Server for {@see \Mcp\Tests\Integration\TasksTest}.
 *
 * A stdio server is one process, so there is no worker to advance a task
 * between polls. The store below stands in for one: each task carries the
 * states it will move through, and every `tasks/get` advances it by one.
 */

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Task;
use Mcp\Server;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\TaskContext;
use Mcp\Server\Task\TaskInputHandlerInterface;
use Mcp\Server\Task\TasksExtension;
use Mcp\Server\Task\TaskStoreInterface;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$store = new class(new InMemoryTaskStore()) implements TaskStoreInterface, TaskInputHandlerInterface {
    /** @var array<string, list<Task>> what each task becomes on its next polls */
    private array $plans = [];

    public function __construct(private readonly TaskStoreInterface $inner)
    {
    }

    /**
     * @param list<Task> $states
     */
    public function plan(string $taskId, array $states): void
    {
        $this->plans[$taskId] = $states;
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

        $next = array_shift($this->plans[$taskId]);
        $this->inner->save($next);

        return $next;
    }

    public function delete(string $taskId): void
    {
        $this->inner->delete($taskId);
    }

    public function receive(Task $task, array $inputResponses): Task
    {
        $name = $inputResponses['name']['content']['name'] ?? 'nobody';

        return $task->with(TaskStatus::Completed, result: new CallToolResult([new TextContent('Hello, '.$name)]));
    }
};

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->enableExtension(new TasksExtension($store, $store))
    ->addTool(
        static function (TaskContext $tasks) use ($store): CreateTaskResult|string {
            if (!$tasks->isSupported()) {
                return 'sync';
            }

            $created = $tasks->create(pollIntervalMs: 10, statusMessage: 'queued');
            $store->plan($created->task->taskId, [
                $created->task->with(TaskStatus::Working, statusMessage: 'halfway'),
                $created->task->with(TaskStatus::Completed, result: new CallToolResult([new TextContent('done')])),
            ]);

            return $created;
        },
        name: 'long_job',
        description: 'Runs as a task when the client can poll, synchronously otherwise.',
    )
    ->addTool(
        static fn (TaskContext $tasks): CreateTaskResult => $tasks->create(pollIntervalMs: 10),
        name: 'task_only',
        description: 'Always creates a task, even for a client that cannot poll.',
    )
    ->addTool(
        static function (TaskContext $tasks) use ($store): CreateTaskResult {
            $created = $tasks->create(pollIntervalMs: 10);
            $store->plan($created->task->taskId, [
                $created->task->with(TaskStatus::InputRequired, inputRequests: [
                    'name' => new ElicitRequest('What is your name?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')])),
                ]),
            ]);

            return $created;
        },
        name: 'ask_job',
        description: 'Parks for the client\'s name, then greets.',
    )
    ->addTool(
        static function (TaskContext $tasks) use ($store): CreateTaskResult {
            $created = $tasks->create(pollIntervalMs: 10);
            $store->plan($created->task->taskId, [$created->task->with(TaskStatus::Failed, error: Error::forInternalError('the worker died'))]);

            return $created;
        },
        name: 'failing_job',
        description: 'Fails at the protocol level.',
    )
    ->addPrompt(
        static function (TaskContext $tasks) use ($store): CreateTaskResult {
            $created = $tasks->create(pollIntervalMs: 10);
            $store->plan($created->task->taskId, [
                $created->task->with(TaskStatus::Completed, result: new GetPromptResult([new PromptMessage(Role::User, new TextContent('prompt done'))])),
            ]);

            return $created;
        },
        name: 'slow_prompt',
        description: 'A prompt served as a task.',
    )
    ->build()
    ->run(new StdioTransport());
