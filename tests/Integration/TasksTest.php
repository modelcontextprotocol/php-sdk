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

use Mcp\Client\Builder as ClientBuilder;
use Mcp\Client\Exception\RequestException;
use Mcp\Client\Exception\RuntimeException;
use Mcp\Client\Task\TaskClient;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Task\TasksExtension;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The Tasks extension (SEP-2663) on 2026-07-28, server and client together:
 * a handle instead of an answer, polled until it settles.
 *
 * @see Fixture/tasks.php for the server under test
 */
final class TasksTest extends IntegrationTestCase
{
    #[TestDox('a task is polled to completion and carries the tool result')]
    public function testTaskIsPolledToCompletion(): void
    {
        $client = $this->connectDeclaring();
        $created = $client->callTool('long_job');

        $this->assertInstanceOf(CreateTaskResult::class, $created);
        $this->assertSame(TaskStatus::Working, $created->task->status);
        $this->assertSame('queued', $created->task->statusMessage);

        $polled = $client->get($created->task->taskId);
        $this->assertSame(TaskStatus::Working, $polled->status);
        $this->assertSame('halfway', $polled->statusMessage);

        $done = $this->settle($client, $created->task->taskId);
        $this->assertSame(TaskStatus::Completed, $done->status);
        $this->assertNotNull($done->result);

        $result = CallToolResult::fromArray($done->result);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('done', $result->content[0]->text);
    }

    #[TestDox('the plain client refuses a task answer and points to TaskClient')]
    public function testPlainClientRefusesATask(): void
    {
        $client = $this->connect('tasks', $this->declaring());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(TaskClient::class.'::callTool()');

        $client->callTool('long_job');
    }

    #[TestDox('a client that did not declare the extension gets the synchronous answer')]
    public function testUndeclaredClientIsServedSynchronously(): void
    {
        $result = $this->connect('tasks', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28))->callTool('long_job');

        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('sync', $result->content[0]->text);
    }

    #[TestDox('below 2026-07-28 the extension does not exist: tools answer synchronously, tasks/* is not found')]
    public function testOlderRevisionHasNoTasks(): void
    {
        $client = new TaskClient($this->connect('tasks', $this->clientBuilder()
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->enableExtension(new TasksExtension())));

        $result = $client->callTool('long_job');
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);
        $this->assertSame('sync', $result->content[0]->text);

        $this->assertErrorCode(Error::METHOD_NOT_FOUND, static fn () => $client->get('anything'));
    }

    #[TestDox('a client that did not declare the extension is refused a task and the tasks/* methods with -32021')]
    public function testUndeclaredClientIsRefused(): void
    {
        $client = new TaskClient($this->connect('tasks', $this->clientBuilder()->setProtocolVersion(ProtocolVersion::V2026_07_28)));

        $this->assertErrorCode(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, static fn () => $client->callTool('task_only'));
        $this->assertErrorCode(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, static fn () => $client->get('anything'));
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

        $this->assertErrorCode(Error::INVALID_PARAMS, static fn () => $client->get('no-such-task'));
    }

    private function declaring(): ClientBuilder
    {
        return $this->clientBuilder()
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->enableExtension(new TasksExtension());
    }

    private function connectDeclaring(): TaskClient
    {
        $client = $this->connect('tasks', $this->declaring());
        $this->assertSame(ProtocolVersion::V2026_07_28, $client->getProtocolVersion());

        return new TaskClient($client);
    }

    private function assertErrorCode(int $code, callable $call): void
    {
        try {
            $call();
            $this->fail(\sprintf('Expected error %d.', $code));
        } catch (RequestException $e) {
            $this->assertSame($code, $e->getError()?->code);
        }
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
