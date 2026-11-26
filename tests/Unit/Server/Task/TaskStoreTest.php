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

use Mcp\Schema\Extension\Tasks\Task;
use Mcp\Schema\Extension\Tasks\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Exception\InvalidArgumentException;
use Mcp\Server\Exception\RuntimeException;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\Psr16TaskStore;
use Mcp\Server\Task\TaskStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class TaskStoreTest extends TestCase
{
    /**
     * @return iterable<string, array{TaskStoreInterface}>
     */
    public static function stores(): iterable
    {
        yield 'in memory' => [new InMemoryTaskStore()];
        yield 'psr-16' => [new Psr16TaskStore(new Psr16Cache(new ArrayAdapter()))];
    }

    /**
     * @return iterable<string, array{TaskStoreInterface, Task}>
     */
    public static function provideRoundTrips(): iterable
    {
        $now = new \DateTimeImmutable();
        $working = new Task('t-1', TaskStatus::Working, $now, $now, 600_000, 250, 'halfway', owner: 'alice');

        $tasks = [
            'working, owned' => $working,
            'completed' => $working->with(TaskStatus::Completed, $now, result: ['content' => [['type' => 'text', 'text' => 'done']]]),
            'failed' => $working->with(TaskStatus::Failed, $now, error: Error::forInternalError('boom')),
        ];

        foreach (self::stores() as $storeName => [$store]) {
            foreach ($tasks as $taskName => $task) {
                yield $storeName.', '.$taskName => [$store, $task];
            }
        }
    }

    #[DataProvider('provideRoundTrips')]
    #[TestDox('a task round-trips through the store, owner included')]
    public function testRoundTrip(TaskStoreInterface $store, Task $task): void
    {
        $store->save($task);

        $read = $store->get($task->taskId);

        $this->assertNotNull($read);
        $this->assertEquals($task->jsonSerialize(), $read->jsonSerialize());
        $this->assertSame($task->owner, $read->owner);
    }

    #[DataProvider('stores')]
    public function testUnknownIdIsNull(TaskStoreInterface $store): void
    {
        $this->assertNull($store->get('nope'));
    }

    #[DataProvider('stores')]
    #[TestDox('a task past its TTL reads as absent')]
    public function testTtlExpiry(TaskStoreInterface $store): void
    {
        $created = new \DateTimeImmutable('-2 seconds');
        $store->save(new Task('t-6', TaskStatus::Working, $created, $created, 1000));

        $this->assertNull($store->get('t-6'));
    }

    #[TestDox('the PSR-16 store refuses to report a task created that the cache did not keep')]
    public function testPsr16SaveFailureThrows(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('set')->willReturn(false);
        $now = new \DateTimeImmutable();

        $this->expectException(RuntimeException::class);

        (new Psr16TaskStore($cache))->save(new Task('t-7', TaskStatus::Working, $now, $now));
    }

    #[TestDox('the PSR-16 store keeps a task without a TTL for as long as the cache does')]
    public function testPsr16UnlimitedTtl(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('set')->with('mcp.tasks.t-8', $this->isType('string'), null)->willReturn(true);
        $now = new \DateTimeImmutable();

        (new Psr16TaskStore($cache))->save(new Task('t-8', TaskStatus::Working, $now, $now));
    }

    #[TestDox('an unreadable stored payload reads as absent rather than throwing')]
    public function testCorruptPayloadIsDropped(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('mcp.tasks.t-9', 'not json at all');

        $this->assertNull((new Psr16TaskStore($cache))->get('t-9'));
    }

    #[TestDox('the in-memory store drops the least recently saved task past its limit')]
    public function testInMemoryEviction(): void
    {
        $now = new \DateTimeImmutable();
        $store = new InMemoryTaskStore(limit: 2);

        $store->save(new Task('a', TaskStatus::Working, $now, $now));
        $store->save(new Task('b', TaskStatus::Working, $now, $now));
        $store->save(new Task('a', TaskStatus::Working, $now, $now));
        $store->save(new Task('c', TaskStatus::Working, $now, $now));

        $this->assertNotNull($store->get('a'));
        $this->assertNull($store->get('b'));
        $this->assertNotNull($store->get('c'));
    }

    #[TestDox('a store that can hold nothing is refused')]
    public function testZeroLimitIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InMemoryTaskStore(limit: 0);
    }
}
