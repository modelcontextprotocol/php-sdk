<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension\Tasks;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Exception\LogicException;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    #[TestDox('a created task is flat on the wire, with ISO-8601 timestamps and nothing it does not carry yet')]
    public function testCreateTaskResultIsFlat(): void
    {
        $data = (new CreateTaskResult(self::task()))->jsonSerialize();

        $this->assertSame([
            'resultType' => 'task',
            'taskId' => 'task-1',
            'status' => 'working',
            'createdAt' => '2026-08-15T10:00:00+00:00',
            'lastUpdatedAt' => '2026-08-15T10:00:00+00:00',
            'ttlMs' => 600_000,
            'pollIntervalMs' => 250,
        ], $data);
    }

    #[TestDox('an unlimited TTL is emitted as null, not omitted')]
    public function testNullTtlIsEmitted(): void
    {
        $data = (new CreateTaskResult(self::task(ttlMs: null)))->jsonSerialize();

        $this->assertArrayHasKey('ttlMs', $data);
        $this->assertNull($data['ttlMs']);
    }

    /**
     * @return iterable<string, array{Task, list<string>}>
     */
    public static function provideStatusPayloads(): iterable
    {
        $now = new \DateTimeImmutable('2026-08-15T10:00:01+00:00');

        yield 'working' => [self::task(), []];
        yield 'completed' => [self::task()->with(TaskStatus::Completed, $now, result: ['content' => []]), ['result']];
        yield 'failed' => [self::task()->with(TaskStatus::Failed, $now, error: Error::forInternalError('boom')), ['error']];
        yield 'cancelled' => [self::task()->with(TaskStatus::Cancelled, $now), []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideStatusPayloads')]
    #[TestDox('a task inlines what its current status carries, and nothing a previous status did')]
    public function testStatusPayloadOnTheWire(Task $task, array $expected): void
    {
        $data = $task->jsonSerialize();

        foreach (['result', 'error'] as $field) {
            $this->assertSame(\in_array($field, $expected, true), \array_key_exists($field, $data), $field);
        }

        $this->assertArrayNotHasKey('owner', $data);
    }

    /**
     * @return iterable<string, array{callable(): Task, string}>
     */
    public static function provideInvalidTasks(): iterable
    {
        $at = new \DateTimeImmutable('2026-08-15T10:00:00+00:00');

        yield 'failed without an error' => [static fn () => new Task('t', TaskStatus::Failed, $at, $at), 'error'];
        yield 'an error on a task that did not fail' => [static fn () => new Task('t', TaskStatus::Completed, $at, $at, result: [], error: Error::forInternalError('boom')), 'isError'];
        yield 'completed without a result' => [static fn () => new Task('t', TaskStatus::Completed, $at, $at), 'result'];
        yield 'a negative TTL' => [static fn () => new Task('t', TaskStatus::Working, $at, $at, ttlMs: -1), 'ttlMs'];
        yield 'a zero poll interval' => [static fn () => new Task('t', TaskStatus::Working, $at, $at, pollIntervalMs: 0), 'pollIntervalMs'];
    }

    /**
     * @param callable(): Task $build
     */
    #[DataProvider('provideInvalidTasks')]
    public function testInvalidTaskIsRefused(callable $build, string $mentions): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mentions);

        $build();
    }

    /**
     * @return iterable<string, array{Task}>
     */
    public static function provideTerminalTasks(): iterable
    {
        $now = new \DateTimeImmutable('2026-08-15T10:00:01+00:00');

        yield 'completed' => [self::task()->with(TaskStatus::Completed, $now, result: [])];
        yield 'failed' => [self::task()->with(TaskStatus::Failed, $now, error: Error::forInternalError('boom'))];
        yield 'cancelled' => [self::task()->with(TaskStatus::Cancelled, $now)];
    }

    #[DataProvider('provideTerminalTasks')]
    #[TestDox('a terminal task never moves again, so a late worker cannot overwrite a cancel')]
    public function testTerminalTaskIsFinal(Task $task): void
    {
        $this->expectException(LogicException::class);

        $task->with(TaskStatus::Completed, new \DateTimeImmutable(), result: []);
    }

    #[TestDox('a task stays readable for its whole TTL, and not past it; without one it always is')]
    public function testTtlBoundsReadability(): void
    {
        $this->assertTrue(self::task()->isReadable(new \DateTimeImmutable('2026-08-15T10:09:59+00:00')));
        $this->assertFalse(self::task()->isReadable(new \DateTimeImmutable('2026-08-15T10:10:01+00:00')));
        $this->assertTrue(self::task(ttlMs: null)->isReadable(new \DateTimeImmutable('2099-01-01T00:00:00+00:00')));
    }

    #[TestDox('timestamps are required to read a task back')]
    public function testFromArrayRequiresTimestamps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Task::fromArray(['taskId' => 't', 'status' => 'working', 'ttlMs' => null]);
    }

    private static function task(?int $ttlMs = 600_000): Task
    {
        $at = new \DateTimeImmutable('2026-08-15T10:00:00+00:00');

        return new Task('task-1', TaskStatus::Working, $at, $at, $ttlMs, 250);
    }
}
