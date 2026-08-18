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

use Mcp\Exception\LogicException;
use Mcp\Exception\MissingRequiredClientCapabilityException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Request\TasksCancelRequest;
use Mcp\Schema\Request\TasksGetRequest;
use Mcp\Schema\Request\TasksUpdateRequest;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Schema\Result\TaskResult;
use Mcp\Schema\Task;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\TaskContext;
use Mcp\Server\Task\TaskInputHandlerInterface;
use Mcp\Server\Task\TasksExtension;
use PHPUnit\Framework\TestCase;

/**
 * The `tasks/*` surface, driven the way the protocol drives it: a request and
 * the session `initialize` left behind.
 */
class TasksExtensionTest extends TestCase
{
    private InMemoryTaskStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
    }

    public function testDeclaresItselfWithoutSettings(): void
    {
        $extension = new TasksExtension($this->store);

        $this->assertSame('io.modelcontextprotocol/tasks', (string) $extension->getId());
        $this->assertSame([], $extension->getCapabilities());
        $this->assertSame([TasksGetRequest::class, TasksUpdateRequest::class, TasksCancelRequest::class], $extension->getMessages());
        $this->assertSame($this->store, $extension->getStore());
    }

    public function testWithoutAStoreItOnlyDeclares(): void
    {
        $extension = new TasksExtension();

        $this->assertSame([], $extension->getCapabilities());
        $this->expectException(LogicException::class);
        $extension->getStore();
    }

    public function testProvidesATaskContextToHandlers(): void
    {
        $providers = (new TasksExtension($this->store))->getArgumentProviders();

        $this->assertSame([TaskContext::class], array_keys($providers));
        $context = $providers[TaskContext::class]($this->session(), PingRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']));
        $this->assertInstanceOf(TaskContext::class, $context);
        $this->assertSame($this->store, $context->getStore());
    }

    public function testTaskContextStoresTheTaskBeforeHandingOutTheHandle(): void
    {
        $context = new TaskContext($this->session(), $this->store);
        $this->assertTrue($context->isSupported());

        $created = $context->create(ttlMs: 60_000, pollIntervalMs: 500, statusMessage: 'Queued.');

        $this->assertSame(TaskStatus::Working, $created->task->status);
        $this->assertSame(60_000, $created->task->ttlMs);
        $this->assertSame(500, $created->task->pollIntervalMs);
        $this->assertSame('Queued.', $created->task->statusMessage);
        $this->assertSame($created->task, $this->store->get($created->task->taskId));
    }

    public function testTaskContextRefusesAHandleToAClientThatCannotRedeemIt(): void
    {
        $context = new TaskContext($this->session(declared: false), $this->store);
        $this->assertFalse($context->isSupported());

        try {
            $context->create();
            $this->fail('Expected the task to be refused.');
        } catch (MissingRequiredClientCapabilityException $e) {
            $this->assertArrayHasKey(TasksExtension::ID, $e->requiredCapabilities->extensions);
            $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $e->toError(1)->code);
        }
    }

    public function testEachMethodHasAHandler(): void
    {
        $handlers = [...(new TasksExtension($this->store))->getRequestHandlers()];

        $this->assertCount(3, $handlers);
        foreach ([$this->get('t'), $this->update('t'), $this->cancel('t')] as $request) {
            $this->assertCount(1, array_filter($handlers, static fn (RequestHandlerInterface $h): bool => $h->supports($request)));
        }
    }

    public function testDeclaredBy(): void
    {
        $this->assertTrue(TasksExtension::declaredBy(['io.modelcontextprotocol/tasks' => []]));
        $this->assertTrue(TasksExtension::declaredBy(['io.modelcontextprotocol/tasks' => new \stdClass()]));
        $this->assertFalse(TasksExtension::declaredBy(['io.modelcontextprotocol/ui' => []]));
        $this->assertFalse(TasksExtension::declaredBy(null));
    }

    public function testGetReturnsTheTaskInDetail(): void
    {
        $this->store->save((new Task('t-1', createdAt: new \DateTimeImmutable('2026-01-01T00:00:00Z')))
            ->with(TaskStatus::Completed, result: ['content' => []], now: new \DateTimeImmutable('2026-01-01T00:00:01Z')));

        $response = $this->handle($this->get('t-1'), $this->session());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(TaskResult::class, $response->result);
        $this->assertSame([
            'taskId' => 't-1',
            'status' => 'completed',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'lastUpdatedAt' => '2026-01-01T00:00:01+00:00',
            'ttlMs' => null,
            'result' => ['content' => []],
        ], $response->result->jsonSerialize());
    }

    public function testUnknownTaskIsInvalidParams(): void
    {
        foreach ([$this->get('nope'), $this->update('nope'), $this->cancel('nope')] as $request) {
            $response = $this->handle($request, $this->session());

            $this->assertInstanceOf(Error::class, $response);
            $this->assertSame(Error::INVALID_PARAMS, $response->code);
            $this->assertSame(['taskId' => 'nope'], $response->data);
        }
    }

    public function testUndeclaredClientIsRefusedWithMissingCapability(): void
    {
        $this->store->save(new Task('t-1'));

        foreach ([$this->get('t-1'), $this->update('t-1'), $this->cancel('t-1')] as $request) {
            $response = $this->handle($request, $this->session(declared: false));

            $this->assertInstanceOf(Error::class, $response);
            $this->assertSame(Error::MISSING_REQUIRED_CLIENT_CAPABILITY, $response->code);
            $this->assertInstanceOf(ClientCapabilities::class, $response->data['requiredCapabilities']);
            $this->assertArrayHasKey(TasksExtension::ID, $response->data['requiredCapabilities']->extensions);
        }

        $this->assertSame(TaskStatus::Working, $this->store->get('t-1')?->status);
    }

    public function testCancelIsCooperativeAndIdempotent(): void
    {
        $this->store->save(new Task('t-1'));

        $response = $this->handle($this->cancel('t-1'), $this->session());
        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
        $this->assertSame(TaskStatus::Cancelled, $this->store->get('t-1')?->status);

        // A second cancel, and a cancel of something finished, change nothing.
        $this->handle($this->cancel('t-1'), $this->session());
        $this->assertSame(TaskStatus::Cancelled, $this->store->get('t-1')->status);

        $this->store->save((new Task('t-2'))->with(TaskStatus::Completed, result: 'done'));
        $this->handle($this->cancel('t-2'), $this->session());
        $this->assertSame(TaskStatus::Completed, $this->store->get('t-2')?->status);
    }

    public function testUpdateHandsTheAnswersToTheInputHandler(): void
    {
        $ask = new ElicitRequest('Who?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')]));
        $this->store->save((new Task('t-1'))->with(TaskStatus::InputRequired, inputRequests: ['who' => $ask]));

        $inputHandler = new class implements TaskInputHandlerInterface {
            /** @var array<string, mixed> */
            public array $received = [];

            public function receive(Task $task, array $inputResponses): Task
            {
                $this->received = $inputResponses;

                return $task->with(TaskStatus::Completed, result: 'Hello, '.$inputResponses['who']['content']['name']);
            }
        };

        $answers = ['who' => ['action' => 'accept', 'content' => ['name' => 'Ada']]];
        $response = $this->handle($this->update('t-1', $answers), $this->session(), $inputHandler);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
        $this->assertSame($answers, $inputHandler->received);
        $this->assertSame(TaskStatus::Completed, $this->store->get('t-1')?->status);
        $this->assertSame('Hello, Ada', $this->store->get('t-1')->result);
    }

    public function testUpdateWithoutAnInputHandlerResumesTheTask(): void
    {
        $ask = new ElicitRequest('Who?', new ElicitationSchema(['name' => new StringSchemaDefinition('Name')]));
        $this->store->save((new Task('t-1'))->with(TaskStatus::InputRequired, inputRequests: ['who' => $ask]));

        $this->handle($this->update('t-1', ['who' => ['action' => 'decline']]), $this->session());

        $this->assertSame(TaskStatus::Working, $this->store->get('t-1')?->status);
    }

    public function testUpdateOfATaskNotWaitingIsIgnored(): void
    {
        $this->store->save(new Task('t-1'));

        $response = $this->handle($this->update('t-1', ['stale' => []]), $this->session());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(TaskStatus::Working, $this->store->get('t-1')?->status);
    }

    /**
     * @return Response<\Mcp\Schema\JsonRpc\ResultInterface>|Error
     */
    private function handle(Request $request, SessionInterface $session, ?TaskInputHandlerInterface $inputHandler = null): Response|Error
    {
        foreach ((new TasksExtension($this->store, $inputHandler))->getRequestHandlers() as $handler) {
            if ($handler->supports($request)) {
                return $handler->handle($request, $session);
            }
        }

        $this->fail(\sprintf('No handler for "%s".', $request::getMethod()));
    }

    /**
     * A session as `initialize` leaves it, with or without the extension declared.
     */
    private function session(bool $declared = true): SessionInterface
    {
        $session = new Session(new InMemorySessionStore());
        $capabilities = new ClientCapabilities(extensions: $declared ? [TasksExtension::ID => new \stdClass()] : null);
        $session->set('client_capabilities', $capabilities->jsonSerialize());

        return $session;
    }

    private function get(string $taskId): TasksGetRequest
    {
        return TasksGetRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get', 'params' => ['taskId' => $taskId]]);
    }

    /**
     * @param array<string, mixed> $inputResponses
     */
    private function update(string $taskId, array $inputResponses = []): TasksUpdateRequest
    {
        return TasksUpdateRequest::fromArray(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tasks/update', 'params' => ['taskId' => $taskId, 'inputResponses' => $inputResponses]]);
    }

    private function cancel(string $taskId): TasksCancelRequest
    {
        return TasksCancelRequest::fromArray(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tasks/cancel', 'params' => ['taskId' => $taskId]]);
    }
}
