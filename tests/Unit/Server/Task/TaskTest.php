<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Task;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\CreateTaskResult;
use Mcp\Schema\Result\TaskResult;
use Mcp\Schema\Task;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    private static function task(TaskStatus $status = TaskStatus::Working): Task
    {
        return new Task(
            'task-1',
            $status,
            new \DateTimeImmutable('2026-08-15T10:00:00+00:00'),
            new \DateTimeImmutable('2026-08-15T10:00:00+00:00'),
            600_000,
            250,
        );
    }

    #[TestDox('a created task is flat on the wire: no nested task wrapper')]
    public function testCreateTaskResultIsFlat(): void
    {
        $data = (new CreateTaskResult(self::task()))->jsonSerialize();

        $this->assertSame('task', $data['resultType']);
        $this->assertSame('task-1', $data['taskId']);
        $this->assertSame('working', $data['status']);
        $this->assertSame(600_000, $data['ttlMs']);
        $this->assertSame(250, $data['pollIntervalMs']);
        $this->assertArrayNotHasKey('task', $data);

        // A task that was just created has nothing to report yet.
        $this->assertArrayNotHasKey('result', $data);
        $this->assertArrayNotHasKey('error', $data);
        $this->assertArrayNotHasKey('inputRequests', $data);
    }

    #[TestDox('the wire fields carry their unit in the name')]
    public function testLegacyWireNamesAreGone(): void
    {
        $data = (new CreateTaskResult(self::task()))->jsonSerialize();

        $this->assertArrayNotHasKey('ttl', $data);
        $this->assertArrayNotHasKey('pollInterval', $data);
    }

    #[TestDox('timestamps are ISO-8601')]
    public function testTimestampsAreIso8601(): void
    {
        $data = (new CreateTaskResult(self::task()))->jsonSerialize();

        $this->assertSame('2026-08-15T10:00:00+00:00', $data['createdAt']);
        $this->assertSame('2026-08-15T10:00:00+00:00', $data['lastUpdatedAt']);
    }

    #[TestDox('an unlimited TTL is emitted as null, not omitted')]
    public function testNullTtlIsEmitted(): void
    {
        $data = (new CreateTaskResult(new Task('task-1', ttlMs: null)))->jsonSerialize();

        $this->assertArrayHasKey('ttlMs', $data);
        $this->assertNull($data['ttlMs']);
    }

    #[TestDox('a completed task inlines the result the request would have returned')]
    public function testCompletedTaskInlinesItsResult(): void
    {
        $task = self::task()->with(TaskStatus::Completed, result: new CallToolResult([new TextContent('done')]));

        $data = (new TaskResult($task))->jsonSerialize();

        $this->assertSame('completed', $data['status']);
        $this->assertSame('done', $data['result']->content[0]->text);
    }

    #[TestDox('a failed task inlines the error and carries no result')]
    public function testFailedTaskInlinesItsError(): void
    {
        $task = self::task()->with(TaskStatus::Failed, error: Error::forInternalError('boom'));

        $data = (new TaskResult($task))->jsonSerialize();

        $this->assertSame('failed', $data['status']);
        $this->assertSame(Error::INTERNAL_ERROR, $data['error']['code']);
        $this->assertSame('boom', $data['error']['message']);
        $this->assertArrayNotHasKey('result', $data);
    }

    #[TestDox('a tool that ran and reported a problem is completed, never failed')]
    public function testToolErrorCannotBeSpelledAsFailed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/isError/');

        new Task('task-1', TaskStatus::Completed, error: Error::forInternalError('boom'));
    }

    #[TestDox('a failed task without an error is refused')]
    public function testFailedTaskNeedsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Task('task-1', TaskStatus::Failed);
    }

    #[TestDox('a waiting task surfaces what it is waiting for')]
    public function testInputRequiredTaskCarriesItsRequests(): void
    {
        $task = self::task()->with(TaskStatus::InputRequired, inputRequests: [
            'who' => new ElicitRequest('Who?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n'])),
        ]);

        $data = (new TaskResult($task))->jsonSerialize();

        $this->assertSame('input_required', $data['status']);
        $this->assertSame('elicitation/create', $data['inputRequests']['who']['method']);
    }

    #[TestDox('terminal states are the ones that never change again')]
    public function testTerminalStates(): void
    {
        $this->assertTrue(TaskStatus::Completed->isTerminal());
        $this->assertTrue(TaskStatus::Failed->isTerminal());
        $this->assertTrue(TaskStatus::Cancelled->isTerminal());
        $this->assertFalse(TaskStatus::Working->isTerminal());
        $this->assertFalse(TaskStatus::InputRequired->isTerminal());
    }

    #[TestDox('a task stays readable for its whole TTL, and not past it')]
    public function testTtlBoundsReadability(): void
    {
        $task = self::task();

        $this->assertTrue($task->isReadable(new \DateTimeImmutable('2026-08-15T10:09:59+00:00')));
        $this->assertFalse($task->isReadable(new \DateTimeImmutable('2026-08-15T10:10:01+00:00')));
    }

    #[TestDox('a task with no TTL is always readable')]
    public function testNoTtlIsAlwaysReadable(): void
    {
        $this->assertTrue((new Task('task-1'))->isReadable(new \DateTimeImmutable('2099-01-01T00:00:00+00:00')));
    }

    #[TestDox('a fractional or negative duration is refused: it is not guidance a client can act on')]
    public function testDurationsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Task('task-1', ttlMs: -1);
    }
}
