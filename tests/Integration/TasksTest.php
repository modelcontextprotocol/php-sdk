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

use Mcp\Client\Exception\RequestException;
use Mcp\Client\Task\TaskClient;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Tasks\CreateTaskResult;
use Mcp\Schema\Extension\Tasks\TasksExtension;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Schema\Result\ReadResourceResult;

/**
 * @author OpenAI
 */
final class TasksTest extends IntegrationTestCase
{
    public function testReceivesTaskHandle(): void
    {
        $client = $this->connectTasks();

        $result = $client->callTool('greet');

        $this->assertInstanceOf(CreateTaskResult::class, $result);
        $this->assertSame('greeting', $result->task->taskId);
        $this->assertSame('working', $result->task->status);
        $this->assertSame(250, $result->task->pollIntervalMs);
    }

    public function testPollsAndAnswersInputRequests(): void
    {
        $client = $this->connectTasks();
        $created = $client->callTool('greet');
        $this->assertInstanceOf(CreateTaskResult::class, $created);
        $taskId = $created->task->taskId;

        $pending = $client->get($taskId);
        $this->assertSame('input_required', $pending->status);
        $this->assertSame('Waiting for a name', $pending->statusMessage);
        $this->assertSame(500, $pending->pollIntervalMs);
        $this->assertNotNull($pending->inputRequests);
        $this->assertArrayHasKey(0, $pending->inputRequests);
        $this->assertSame('elicitation/create', $pending->inputRequests[0]['method']);
        $this->assertSame('What is your name?', $pending->inputRequests[0]['params']['message']);
        $this->assertSame($pending->inputRequests, $client->get($taskId)->inputRequests);

        $client->update($taskId, [0 => new ElicitResult(ElicitAction::Accept, ['name' => 'Ada'])]);

        $completed = $client->get($taskId);
        $this->assertSame('completed', $completed->status);
        $this->assertNull($completed->inputRequests);
        $this->assertNotNull($completed->result);
        $message = CallToolResult::fromArray($completed->result)->content[0];
        $this->assertInstanceOf(TextContent::class, $message);
        $this->assertSame('Hello, Ada!', $message->text);
    }

    public function testPollsFailedTaskAndCancelsAnotherTask(): void
    {
        $client = $this->connectTasks();
        $failed = $client->callTool('fail');
        $this->assertInstanceOf(CreateTaskResult::class, $failed);
        $task = $client->get($failed->task->taskId);
        $this->assertSame('failed', $task->status);
        $this->assertSame(['code' => -32603, 'message' => 'Task failed'], $task->error);

        $working = $client->callTool('greet');
        $this->assertInstanceOf(CreateTaskResult::class, $working);
        $client->cancel($working->task->taskId);
        $this->assertSame('cancelled', $client->get($working->task->taskId)->status);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessage('Unknown task');
        $client->get('unknown');
    }

    public function testReturnsOrdinaryResultsAndDecodesTaskResultsForSiblingMethods(): void
    {
        $client = $this->connectTasks();

        $tool = $client->callTool('immediate');
        $prompt = $client->getPrompt('immediate');
        $resource = $client->readResource('test://immediate');

        $this->assertInstanceOf(CallToolResult::class, $tool);
        $this->assertInstanceOf(TextContent::class, $tool->content[0]);
        $this->assertSame('Immediate', $tool->content[0]->text);
        $this->assertInstanceOf(GetPromptResult::class, $prompt);
        $this->assertSame('Immediate', $prompt->description);
        $this->assertInstanceOf(ReadResourceResult::class, $resource);
        $this->assertSame('test://immediate', $resource->contents[0]->uri);

        $this->assertInstanceOf(CreateTaskResult::class, $client->getPrompt('deferred'));
        $this->assertInstanceOf(CreateTaskResult::class, $client->readResource('test://deferred'));
    }

    public function testAdvertisesExtensionDuringInitialize(): void
    {
        $client = $this->connect('tasks', $this->clientBuilder()
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)
            ->enableExtension(new TasksExtension()));

        $this->assertSame(ProtocolVersion::V2025_11_25, $client->getProtocolVersion());
    }

    private function connectTasks(): TaskClient
    {
        return new TaskClient($this->connect('tasks', $this->clientBuilder()
            ->setProtocolVersion(ProtocolVersion::V2026_07_28)
            ->enableExtension(new TasksExtension())));
    }
}
