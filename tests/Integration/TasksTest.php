<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Task\TaskClient;
use Mcp\Exception\RequestException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Task;
use Mcp\Server\Task\TasksExtension;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The Tasks extension (SEP-2663), server and client together: a handle
 * instead of an answer, polled until it settles.
 *
 * @see Fixture/tasks.php for the server under test
 */
final class TasksTest extends IntegrationTestCase
{
    #[TestDox('the server announces the extension with empty settings')]
    public function testExtensionIsAnnounced(): void
    {
        $client = $this->connectDeclaring();

        $result = $client->callTool('long_job');

        $this->assertInstanceOf(CreateTaskResult::class, $result);
        $this->assertSame(TaskStatus::Working, $result->task->status);
        $this->assertSame('queued', $result->task->statusMessage);
        $this->assertSame(10, $result->task->pollIntervalMs);
        $this->assertNotNull($result->task->createdAt);
    }

    #[TestDox('a task is polled to completion and carries the tool result')]
    public function testTaskIsPolledToCompletion(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->callTool('long_job');
        $this->assertInstanceOf(CreateTaskResult::class, $created);

        $polled = $client->get($created->task->taskId);
        $this->assertSame(TaskStatus::Working, $polled->status);
        $this->assertSame('halfway', $polled->statusMessage);

        $done = $this->settle($client, $created->task->taskId);
        $this->assertSame(TaskStatus::Completed, $done->status);
        $this->assertNull($done->error);

        $result = CallToolResult::fromArray($done->result);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('done', $result->content[0]->text);
    }

    #[TestDox('a client that did not declare the extension gets the synchronous answer')]
    public function testUndeclaredClientIsServedSynchronously(): void
    {
        $client = $this->connect('tasks');

        $result = $client->callTool('long_job');

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('sync', $result->content[0]->text);
    }

    #[TestDox('a task-only tool refuses a client that cannot poll with -32021')]
    public function testUndeclaredClientIsRefusedARequiredTask(): void
    {
        $client = $this->connect('tasks');

        try {
            $client->callTool('task_only');
            $this->fail('Expected the task to be refused.');
        } catch (RequestException $e) {
            $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $e->getError()?->code);
            $this->assertArrayHasKey(TasksExtension::ID, $e->getError()->data['requiredCapabilities']['extensions']);
        }
    }

    #[TestDox('the tasks/* methods themselves are refused to a client that did not declare the extension')]
    public function testUndeclaredClientCannotPoll(): void
    {
        $client = new TaskClient($this->connect('tasks'));

        try {
            $client->get('anything');
            $this->fail('Expected tasks/get to be refused.');
        } catch (RequestException $e) {
            $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $e->getError()?->code);
        }
    }

    #[TestDox('a parked task exposes what it asks for, and an answer through tasks/update completes it')]
    public function testInputRequiredRoundTrip(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->callTool('ask_job');
        $this->assertInstanceOf(CreateTaskResult::class, $created);

        $parked = $client->get($created->task->taskId);
        $this->assertSame(TaskStatus::InputRequired, $parked->status);
        $this->assertArrayHasKey('name', $parked->inputRequests);
        $this->assertInstanceOf(ElicitRequest::class, $parked->inputRequests['name']);
        $this->assertSame('What is your name?', $parked->inputRequests['name']->message);

        $client->update($created->task->taskId, ['name' => ['action' => 'accept', 'content' => ['name' => 'Ada']]]);

        $done = $client->get($created->task->taskId);
        $this->assertSame(TaskStatus::Completed, $done->status);
        $this->assertSame('Hello, Ada', CallToolResult::fromArray($done->result)->content[0]->text ?? null);
    }

    #[TestDox('a failed task carries the error inline and no result')]
    public function testFailedTask(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->callTool('failing_job');
        $this->assertInstanceOf(CreateTaskResult::class, $created);

        $failed = $this->settle($client, $created->task->taskId);

        $this->assertSame(TaskStatus::Failed, $failed->status);
        $this->assertNull($failed->result);
        $this->assertSame(Error::INTERNAL_ERROR, $failed->error?->code);
        $this->assertSame('the worker died', $failed->error->message);
    }

    #[TestDox('cancelling is cooperative and idempotent')]
    public function testCancel(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->callTool('task_only');
        $this->assertInstanceOf(CreateTaskResult::class, $created);

        $client->cancel($created->task->taskId);
        $this->assertSame(TaskStatus::Cancelled, $client->get($created->task->taskId)->status);

        $client->cancel($created->task->taskId);
        $this->assertSame(TaskStatus::Cancelled, $client->get($created->task->taskId)->status);
    }

    #[TestDox('an unknown task is invalid params, not a missing method')]
    public function testUnknownTask(): void
    {
        $client = $this->connectDeclaring();

        try {
            $client->get('no-such-task');
            $this->fail('Expected tasks/get to fail.');
        } catch (RequestException $e) {
            $this->assertSame(Error::INVALID_PARAMS, $e->getError()?->code);
        }
    }

    #[TestDox('a prompt can be served as a task too')]
    public function testPromptAsTask(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->getPrompt('slow_prompt');
        $this->assertInstanceOf(CreateTaskResult::class, $created);

        $done = $this->settle($client, $created->task->taskId);

        $this->assertSame(TaskStatus::Completed, $done->status);
        $prompt = GetPromptResult::fromArray($done->result);
        $this->assertSame('prompt done', $prompt->messages[0]->content->text ?? null);
    }

    private function connectDeclaring(): TaskClient
    {
        return new TaskClient($this->connect('tasks', $this->clientBuilder()->enableExtension(new TasksExtension())));
    }

    /**
     * Polls until the task reaches a terminal status, honouring `pollIntervalMs`.
     */
    private function settle(TaskClient $client, string $taskId): Task
    {
        for ($i = 0; $i < 20; ++$i) {
            $task = $client->get($taskId);

            if ($task->status->isTerminal()) {
                return $task;
            }

            usleep(1000 * ($task->pollIntervalMs ?? 10));
        }

        $this->fail(\sprintf('Task "%s" did not settle.', $taskId));
    }
}
