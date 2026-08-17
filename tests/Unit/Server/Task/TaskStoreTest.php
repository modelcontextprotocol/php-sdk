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
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Schema\Enum\TaskStatus;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Task;
use Mcp\Server\Task\InMemoryTaskStore;
use Mcp\Server\Task\Psr16TaskStore;
use Mcp\Server\Task\TaskStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

class TaskStoreTest extends TestCase
{
    /**
     * @return iterable<string, array{TaskStoreInterface}>
     */
    public static function stores(): iterable
    {
        yield 'in memory' => [new InMemoryTaskStore()];
        yield 'psr-16' => [new Psr16TaskStore(self::arrayCache())];
    }

    #[DataProvider('stores')]
    #[TestDox('a task round-trips through the store')]
    public function testRoundTrip(TaskStoreInterface $store): void
    {
        $store->save(new Task('t-1', TaskStatus::Working, new \DateTimeImmutable(), new \DateTimeImmutable(), 600_000, 250, 'halfway'));

        $task = $store->get('t-1');

        $this->assertNotNull($task);
        $this->assertSame('t-1', $task->taskId);
        $this->assertSame(TaskStatus::Working, $task->status);
        $this->assertSame(600_000, $task->ttlMs);
        $this->assertSame(250, $task->pollIntervalMs);
        $this->assertSame('halfway', $task->statusMessage);
    }

    #[DataProvider('stores')]
    #[TestDox('an unknown id reads as absent')]
    public function testUnknownIdIsNull(TaskStoreInterface $store): void
    {
        $this->assertNull($store->get('nope'));
    }

    #[DataProvider('stores')]
    #[TestDox('a saved task overwrites the earlier state under the same id')]
    public function testSaveOverwrites(TaskStoreInterface $store): void
    {
        $task = new Task('t-2', TaskStatus::Working, new \DateTimeImmutable(), new \DateTimeImmutable(), 600_000);
        $store->save($task);
        $store->save($task->with(TaskStatus::Cancelled));

        $this->assertSame(TaskStatus::Cancelled, $store->get('t-2')?->status);
    }

    #[DataProvider('stores')]
    #[TestDox('a failed task keeps its error across the store')]
    public function testErrorSurvives(TaskStoreInterface $store): void
    {
        $task = (new Task('t-3', TaskStatus::Working, new \DateTimeImmutable(), null, 600_000))
            ->with(TaskStatus::Failed, error: Error::forInternalError('boom'));

        $store->save($task);

        $read = $store->get('t-3');
        $this->assertNotNull($read);
        $this->assertNotNull($read->error);
        $this->assertSame(Error::INTERNAL_ERROR, $read->error->code);
        $this->assertSame('boom', $read->error->message);
    }

    #[DataProvider('stores')]
    #[TestDox('a parked task still knows what it is waiting for after a round trip')]
    public function testInputRequestsSurvive(TaskStoreInterface $store): void
    {
        $task = (new Task('t-4', TaskStatus::Working, new \DateTimeImmutable(), null, 600_000))
            ->with(TaskStatus::InputRequired, inputRequests: [
                'who' => new ElicitRequest('Who?', new ElicitationSchema(['n' => new StringSchemaDefinition('N')], ['n'])),
            ]);

        $store->save($task);

        $read = $store->get('t-4');
        $this->assertNotNull($read);
        $this->assertArrayHasKey('who', $read->inputRequests);
        $this->assertInstanceOf(ElicitRequest::class, $read->inputRequests['who']);
        $this->assertSame('Who?', $read->inputRequests['who']->message);
    }

    #[DataProvider('stores')]
    #[TestDox('a deleted task is gone')]
    public function testDelete(TaskStoreInterface $store): void
    {
        $store->save(new Task('t-5'));
        $store->delete('t-5');

        $this->assertNull($store->get('t-5'));
    }

    #[TestDox('a task past its TTL reads as absent')]
    public function testTtlExpiry(): void
    {
        $created = new \DateTimeImmutable('2026-08-15T10:00:00+00:00');
        $clock = new class($created) implements ClockInterface {
            public function __construct(public \DateTimeImmutable $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        $store = new InMemoryTaskStore(clock: $clock);
        $store->save(new Task('t-6', TaskStatus::Working, $created, $created, 1000));

        $this->assertNotNull($store->get('t-6'));

        $clock->now = $created->modify('+2 seconds');
        $this->assertNull($store->get('t-6'));
    }

    #[TestDox('the in-memory store drops the least recently used task past its limit')]
    public function testInMemoryEviction(): void
    {
        $store = new InMemoryTaskStore(limit: 2);

        $store->save(new Task('a'));
        $store->save(new Task('b'));
        // Touching `a` makes `b` the least recently used.
        $store->save(new Task('a', TaskStatus::Working));
        $store->save(new Task('c'));

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

    #[TestDox('an unreadable stored payload reads as absent rather than throwing')]
    public function testCorruptPayloadIsDropped(): void
    {
        $cache = self::arrayCache();
        $cache->set('mcp.tasks.t-7', 'not json at all');

        $this->assertNull((new Psr16TaskStore($cache))->get('t-7'));
    }

    private static function arrayCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->values = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                foreach ($keys as $key) {
                    yield $key => $this->get($key, $default);
                }
            }

            public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return \array_key_exists($key, $this->values);
            }
        };
    }
}
