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
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TasksExtension;
use Mcp\Schema\Extension\Tasks\UpdateTaskRequest;
use Mcp\Schema\MessageFactory;
use PHPUnit\Framework\TestCase;

/**
 * @author OpenAI
 */
final class TasksExtensionTest extends TestCase
{
    public function testTaskResultKeepsFieldsInlineAndPreservesEmptyObjects(): void
    {
        $task = Task::fromArray([
            'taskId' => 'task-1',
            'status' => 'completed',
            'createdAt' => '2026-07-28T12:00:00Z',
            'lastUpdatedAt' => '2026-07-28T12:00:01Z',
            'ttlMs' => null,
            'pollIntervalMs' => 0,
            'result' => [],
        ]);

        $result = new CreateTaskResult($task, []);
        $this->assertEquals($result, CreateTaskResult::fromArray($result->jsonSerialize()));
        $this->assertJsonStringEqualsJsonString(
            '{"resultType":"task","taskId":"task-1","status":"completed","createdAt":"2026-07-28T12:00:00Z","lastUpdatedAt":"2026-07-28T12:00:01Z","ttlMs":null,"pollIntervalMs":0,"result":{},"_meta":{}}',
            json_encode($result, \JSON_THROW_ON_ERROR),
        );
    }

    public function testTaskRequiresTtlEvenWhenItIsUnlimited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing or invalid "ttlMs" in task data.');

        Task::fromArray([
            'taskId' => 'task-1',
            'status' => 'working',
            'createdAt' => '2026-07-28T12:00:00Z',
            'lastUpdatedAt' => '2026-07-28T12:00:00Z',
        ]);
    }

    public function testMessageFactoryRoundTripsAnUpdateWithNumericInputKeys(): void
    {
        $request = (new UpdateTaskRequest('task-1', [0 => [], 1 => ['action' => 'decline']]))->withId(1);
        $encoded = json_encode($request, \JSON_THROW_ON_ERROR);

        $this->assertJsonStringEqualsJsonString(
            '{"jsonrpc":"2.0","id":1,"method":"tasks/update","params":{"taskId":"task-1","inputResponses":{"0":{},"1":{"action":"decline"}}}}',
            $encoded,
        );
        $decoded = MessageFactory::make((new TasksExtension())->getMessages())->create($encoded);
        $this->assertInstanceOf(UpdateTaskRequest::class, $decoded);
        $this->assertSame($encoded, json_encode($decoded, \JSON_THROW_ON_ERROR));
    }
}
