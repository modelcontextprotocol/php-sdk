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

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TasksCancelRequest;
use Mcp\Schema\Extension\Tasks\TasksGetRequest;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\EmptyResult;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\TaskManager;
use Mcp\Server\Task\TasksExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class TasksExtensionTest extends TestCase
{
    private InMemoryTaskStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
    }

    public function testDeclaresItselfWithoutSettings(): void
    {
        $extension = new TasksExtension();

        $this->assertSame('io.modelcontextprotocol/tasks', (string) $extension->getId());
        $this->assertSame([], $extension->getCapabilities());
        $this->assertSame([TasksGetRequest::class, TasksCancelRequest::class], $extension->getMessages());
    }

    #[TestDox('serving tasks/* needs a task manager')]
    public function testServingWithoutAManagerIsRefused(): void
    {
        $this->expectException(LogicException::class);

        iterator_to_array((new TasksExtension())->getRequestHandlers());
    }

    /**
     * @return iterable<string, array{Request}>
     */
    public static function provideRequests(): iterable
    {
        yield 'tasks/get' => [new TasksGetRequest('t-1')];
        yield 'tasks/cancel' => [new TasksCancelRequest('t-1')];
    }

    #[DataProvider('provideRequests')]
    #[TestDox('below 2026-07-28 the methods do not exist')]
    public function testOlderRevisionIsMethodNotFound(Request $request): void
    {
        $this->store->save(self::task());

        $response = $this->handle($request, self::session(version: ProtocolVersion::V2025_11_25));

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::METHOD_NOT_FOUND, $response->code);
    }

    #[DataProvider('provideRequests')]
    #[TestDox('a client that did not declare the extension is refused with -32021')]
    public function testUndeclaredClientIsRefused(Request $request): void
    {
        $this->store->save(self::task());

        $this->expectException(MissingRequiredClientCapabilityException::class);

        $this->handle($request, self::session(declared: false));
    }

    /**
     * @return iterable<string, array{Request, ?AccessToken}>
     */
    public static function provideUnknownTasks(): iterable
    {
        $mallory = new AccessToken(claims: ['client_id' => 'app', 'sub' => 'mallory']);

        yield 'tasks/get, no such id' => [new TasksGetRequest('nope'), null];
        yield 'tasks/cancel, no such id' => [new TasksCancelRequest('nope'), null];
        yield 'tasks/get, someone else\'s task' => [new TasksGetRequest('t-1'), $mallory];
        yield 'tasks/cancel, someone else\'s task' => [new TasksCancelRequest('t-1'), $mallory];
    }

    #[DataProvider('provideUnknownTasks')]
    #[TestDox('an unknown task and someone else\'s task are the same invalid params')]
    public function testUnknownTaskIsInvalidParams(Request $request, ?AccessToken $caller): void
    {
        $this->store->save(self::task(owner: 'opaque owner of alice'));

        $response = $this->handle($request, self::session(token: $caller));

        $this->assertInstanceOf(Error::class, $response);
        $this->assertSame(Error::INVALID_PARAMS, $response->code);
        $this->assertStringContainsString('Unknown task', $response->message);
    }

    #[TestDox('tasks/get answers with the task itself')]
    public function testGetReturnsTheTask(): void
    {
        $this->store->save(self::task());

        $response = $this->handle(new TasksGetRequest('t-1'), self::session());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(Task::class, $response->result);
        $this->assertSame('t-1', $response->result->taskId);
    }

    /**
     * @return iterable<string, array{Task, TaskStatus}>
     */
    public static function provideCancellations(): iterable
    {
        yield 'working' => [self::task(), TaskStatus::Cancelled];
        yield 'already completed' => [self::task()->with(TaskStatus::Completed, new \DateTimeImmutable(), result: []), TaskStatus::Completed];
        yield 'already cancelled' => [self::task()->with(TaskStatus::Cancelled, new \DateTimeImmutable()), TaskStatus::Cancelled];
    }

    #[DataProvider('provideCancellations')]
    #[TestDox('tasks/cancel is acknowledged, and a terminal task is left as it is')]
    public function testCancel(Task $task, TaskStatus $expected): void
    {
        $this->store->save($task);

        $response = $this->handle(new TasksCancelRequest('t-1'), self::session());

        $this->assertInstanceOf(Response::class, $response);
        $this->assertInstanceOf(EmptyResult::class, $response->result);
        $this->assertSame($expected, $this->store->get('t-1')?->status);
    }

    /**
     * @return Response<\Mcp\Schema\JsonRpc\ResultInterface>|Error
     */
    private function handle(Request $request, SessionInterface $session): Response|Error
    {
        foreach ((new TasksExtension(new TaskManager($this->store)))->getRequestHandlers() as $handler) {
            if ($handler->supports($request)) {
                return $handler->handle($request->withId(1), $session);
            }
        }

        $this->fail(\sprintf('No handler for "%s".', $request::getMethod()));
    }

    private static function task(?string $owner = null): Task
    {
        $now = new \DateTimeImmutable();

        return new Task('t-1', TaskStatus::Working, $now, $now, owner: $owner);
    }

    private static function session(bool $declared = true, ProtocolVersion $version = ProtocolVersion::V2026_07_28, ?AccessToken $token = null): SessionInterface
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('client_capabilities', (new ClientCapabilities(extensions: $declared ? [TasksExtension::ID => new \stdClass()] : null))->jsonSerialize());
        $session->set('protocol_version', $version->value);
        $session->set(AccessToken::class, $token);

        return $session;
    }
}
