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
use Mcp\Schema\Exception\LogicException as SchemaLogicException;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Exception\LogicException;
use Mcp\Server\Exception\MissingRequiredClientCapabilityException;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\TaskManager;
use Mcp\Server\Task\TasksExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

class TaskManagerTest extends TestCase
{
    private InMemoryTaskStore $store;
    private TaskManager $tasks;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn (): \DateTimeImmutable => $this->now);

        $this->store = new InMemoryTaskStore(clock: $clock);
        $this->tasks = new TaskManager($this->store, $clock);
    }

    #[TestDox('a task is stored under a random UUIDv4, bound to its creator, before the handle is handed out')]
    public function testCreateStoresTheTaskFirst(): void
    {
        $token = new AccessToken(claims: ['client_id' => 'app', 'sub' => 'alice']);

        $created = $this->tasks->create(self::context(token: $token), ttlMs: 60_000, pollIntervalMs: 500, statusMessage: 'Queued.');

        $this->assertInstanceOf(UuidV4::class, Uuid::fromString($created->task->taskId));
        $this->assertSame(TaskStatus::Working, $created->task->status);
        $this->assertEquals($this->now, $created->task->createdAt);
        $this->assertEquals($this->now, $created->task->lastUpdatedAt);
        $this->assertNotNull($created->task->owner);
        $this->assertSame($created->task, $this->store->get($created->task->taskId));
    }

    /**
     * @return iterable<string, array{RequestContext}>
     */
    public static function provideClientsThatCannotPoll(): iterable
    {
        yield 'a client that did not declare the extension' => [self::context(declared: false)];
        yield 'a revision the extension does not exist in' => [self::context(version: ProtocolVersion::V2025_11_25)];
    }

    #[DataProvider('provideClientsThatCannotPoll')]
    #[TestDox('a handle is refused to a client that could not redeem it')]
    public function testCreateRefusesAClientThatCannotPoll(RequestContext $context): void
    {
        $this->assertFalse($this->tasks->isSupported($context));

        try {
            $this->tasks->create($context);
            $this->fail('Expected the task to be refused.');
        } catch (MissingRequiredClientCapabilityException $e) {
            $this->assertSame(['extensions' => [TasksExtension::ID => []]], json_decode(json_encode($e->requiredCapabilities, \JSON_THROW_ON_ERROR), true));
        }
    }

    #[TestDox('only tools/call can be answered with a task')]
    public function testCreateRefusesOtherMethods(): void
    {
        $this->expectException(LogicException::class);

        $this->tasks->create(self::context(request: new GetPromptRequest('greet')));
    }

    /**
     * @return iterable<string, array{?AccessToken, bool}>
     */
    public static function provideCallers(): iterable
    {
        yield 'the same client and subject' => [new AccessToken(claims: ['client_id' => 'app', 'sub' => 'alice', 'scope' => 'other']), true];
        yield 'another subject of the same client' => [new AccessToken(claims: ['client_id' => 'app', 'sub' => 'mallory']), false];
        yield 'the same subject through another client' => [new AccessToken(claims: ['client_id' => 'evil', 'sub' => 'alice']), false];
        yield 'an unauthenticated caller' => [null, false];
    }

    #[DataProvider('provideCallers')]
    #[TestDox('a task is only found for the client and subject that created it')]
    public function testFindChecksTheOwner(?AccessToken $caller, bool $found): void
    {
        $created = $this->tasks->create(self::context(token: new AccessToken(claims: ['client_id' => 'app', 'sub' => 'alice'])));

        $this->assertSame($found, null !== $this->tasks->find(self::context(token: $caller), $created->task->taskId));
    }

    #[TestDox('without authentication the task id is the bearer token')]
    public function testUnownedTaskIsFoundByItsId(): void
    {
        $created = $this->tasks->create(self::context());

        $this->assertNotNull($this->tasks->find(self::context(token: new AccessToken(claims: ['sub' => 'anyone'])), $created->task->taskId));
    }

    #[TestDox('a worker cannot overwrite a cancellation')]
    public function testTransitionRefusesATerminalTask(): void
    {
        $created = $this->tasks->create(self::context());
        $this->now = $this->now->modify('+1 second');
        $this->tasks->cancel($created->task);

        try {
            $this->tasks->transition($created->task->taskId, TaskStatus::Completed, result: ['content' => []]);
            $this->fail('Expected the transition to be refused.');
        } catch (SchemaLogicException) {
        }

        $this->assertSame(TaskStatus::Cancelled, $this->store->get($created->task->taskId)?->status);
        $this->assertEquals($this->now, $this->store->get($created->task->taskId)->lastUpdatedAt);
    }

    private static function context(bool $declared = true, ProtocolVersion $version = ProtocolVersion::V2026_07_28, ?AccessToken $token = null, ?Request $request = null): RequestContext
    {
        $session = new Session(new InMemorySessionStore());
        $session->set('client_capabilities', (new ClientCapabilities(extensions: $declared ? [TasksExtension::ID => new \stdClass()] : null))->jsonSerialize());
        $session->set('protocol_version', $version->value);
        $session->set(AccessToken::class, $token);

        return new RequestContext($session, $request ?? new CallToolRequest('long_job', []));
    }
}
