<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Subscription;

use Mcp\Event\ToolListChangedEvent;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\NotificationBusInterface;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Subscription\Psr6NotificationBus;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NotificationBusTest extends TestCase
{
    /**
     * @return iterable<string, array{NotificationBusInterface}>
     */
    public static function buses(): iterable
    {
        yield 'in memory' => [new InMemoryNotificationBus()];
        yield 'psr-16' => [new Psr16NotificationBus(self::arrayCache())];
        yield 'psr-6' => [new Psr6NotificationBus(new ArrayAdapter())];
    }

    /**
     * @return iterable<string, array{\Closure(int): NotificationBusInterface}>
     */
    public static function boundedBuses(): iterable
    {
        yield 'in memory' => [static fn (int $backlog) => new InMemoryNotificationBus(backlog: $backlog)];
        yield 'psr-16' => [static fn (int $backlog) => new Psr16NotificationBus(self::arrayCache(), backlog: $backlog)];
        yield 'psr-6' => [static fn (int $backlog) => new Psr6NotificationBus(new ArrayAdapter(), backlog: $backlog)];
    }

    /**
     * @return iterable<string, array{\Closure(int): array{NotificationBusInterface, \Closure(): bool}}>
     */
    public static function cacheBuses(): iterable
    {
        yield 'psr-16' => [static function (int $ttl): array {
            $cache = self::arrayCache();

            return [new Psr16NotificationBus($cache, ttl: $ttl), $cache->clear(...)];
        }];
        yield 'psr-6' => [static function (int $ttl): array {
            $pool = new ArrayAdapter();

            return [new Psr6NotificationBus($pool, ttl: $ttl), $pool->clear(...)];
        }];
    }

    /**
     * @return iterable<string, array{NotificationBusInterface, \Closure(string): mixed}>
     */
    public static function busesWithRawAccess(): iterable
    {
        $cache = self::arrayCache();
        yield 'psr-16' => [new Psr16NotificationBus($cache, prefix: 'bus.'), static fn (string $raw) => $cache->set('bus.0', $raw)];

        $pool = new ArrayAdapter();
        yield 'psr-6' => [new Psr6NotificationBus($pool, prefix: 'bus.'), static fn (string $raw) => $pool->save($pool->getItem('bus.0')->set($raw))];
    }

    /**
     * @param \Closure(string): mixed $overwriteFirstEntry
     */
    #[DataProvider('busesWithRawAccess')]
    #[TestDox('an unreadable entry is dropped without losing the ones after it')]
    public function testUnreadableEntryIsDropped(NotificationBusInterface $bus, \Closure $overwriteFirstEntry): void
    {
        $cursor = $bus->cursor();
        $bus->publish(new ToolListChangedNotification());
        $bus->publish(new PromptListChangedNotification());

        $overwriteFirstEntry('[{"jsonrpc":"2.0","method":"notifications/tools/list_changed"}]');

        [$found] = $bus->since($cursor);

        $this->assertCount(1, $found);
        $this->assertInstanceOf(PromptListChangedNotification::class, $found[0]);
    }

    #[DataProvider('buses')]
    #[TestDox('a subscriber reads what is published after it opened')]
    public function testReadsForwardFromItsCursor(NotificationBusInterface $bus): void
    {
        $bus->publish(new ToolListChangedNotification());

        // Opened after the first notification, so it must not see it.
        $cursor = $bus->cursor();

        $bus->publish(new PromptListChangedNotification());
        $bus->publish(new ResourceUpdatedNotification('file:///a'));

        [$found, $next] = $bus->since($cursor);

        $this->assertCount(2, $found);
        $this->assertInstanceOf(PromptListChangedNotification::class, $found[0]);
        $this->assertInstanceOf(ResourceUpdatedNotification::class, $found[1]);
        $this->assertSame('file:///a', $found[1]->uri);

        // Reading again from the returned cursor yields nothing new.
        [$again] = $bus->since($next);
        $this->assertSame([], $again);
    }

    #[DataProvider('buses')]
    #[TestDox('a quiet bus hands back nothing')]
    public function testQuietBusIsEmpty(NotificationBusInterface $bus): void
    {
        [$found] = $bus->since($bus->cursor());

        $this->assertSame([], $found);
    }

    #[DataProvider('buses')]
    #[TestDox('two subscribers at different cursors each get their own view')]
    public function testCursorsAreIndependent(NotificationBusInterface $bus): void
    {
        $early = $bus->cursor();
        $bus->publish(new ToolListChangedNotification());
        $late = $bus->cursor();
        $bus->publish(new PromptListChangedNotification());

        $this->assertCount(2, $bus->since($early)[0]);
        $this->assertCount(1, $bus->since($late)[0]);
    }

    /**
     * @param \Closure(int): NotificationBusInterface $createBus
     */
    #[DataProvider('boundedBuses')]
    #[TestDox('the backlog is bounded, so a stream that went away cannot grow it forever')]
    public function testBacklogIsBounded(\Closure $createBus): void
    {
        $bus = $createBus(3);

        $cursor = $bus->cursor();
        for ($i = 0; $i < 10; ++$i) {
            $bus->publish(new ToolListChangedNotification());
        }

        $this->assertCount(3, $bus->since($cursor)[0]);
    }

    /**
     * @param \Closure(int): NotificationBusInterface $createBus
     */
    #[DataProvider('boundedBuses')]
    #[TestDox('a subscriber that fell behind the backlog reads the newest entries, oldest first')]
    public function testAFallenBehindSubscriberReadsTheNewestInOrder(\Closure $createBus): void
    {
        $bus = $createBus(2);
        $cursor = $bus->cursor();

        $bus->publish(new ToolListChangedNotification());
        $bus->publish(new PromptListChangedNotification());
        $bus->publish(new ResourceUpdatedNotification('file:///a'));

        [$found, $next] = $bus->since($cursor);

        $this->assertCount(2, $found);
        $this->assertInstanceOf(PromptListChangedNotification::class, $found[0]);
        $this->assertInstanceOf(ResourceUpdatedNotification::class, $found[1]);
        $this->assertSame($bus->cursor(), $next);
    }

    #[DataProvider('buses')]
    #[TestDox('a subscriber ahead of the bus is handed back the bus cursor')]
    public function testASubscriberAheadOfTheBusIsBroughtBack(NotificationBusInterface $bus): void
    {
        $bus->publish(new ToolListChangedNotification());

        [, $next] = $bus->since($bus->cursor() + 5);

        $this->assertSame($bus->cursor(), $next);
    }

    /**
     * @param \Closure(int): NotificationBusInterface $createBus
     */
    #[DataProvider('boundedBuses')]
    #[TestDox('a backlog below one entry is refused')]
    public function testZeroBacklogIsRefused(\Closure $createBus): void
    {
        $this->expectException(InvalidArgumentException::class);

        $createBus(0);
    }

    /**
     * @param \Closure(int): array{NotificationBusInterface, \Closure(): bool} $createBus
     */
    #[DataProvider('cacheBuses')]
    #[TestDox('the sequence outlives its entries, so a bus that went quiet does not start over')]
    public function testSequenceOutlivesItsEntries(\Closure $createBus): void
    {
        [$bus] = $createBus(0);

        $bus->publish(new ToolListChangedNotification());
        $bus->publish(new ToolListChangedNotification());

        $this->assertSame(2, $bus->cursor());
    }

    /**
     * @param \Closure(int): array{NotificationBusInterface, \Closure(): bool} $createBus
     */
    #[DataProvider('cacheBuses')]
    #[TestDox('a subscriber ahead of a sequence that started over reads what comes next')]
    public function testSubscriberAheadOfALostSequence(\Closure $createBus): void
    {
        [$bus, $clear] = $createBus(120);

        $bus->publish(new ToolListChangedNotification());
        $bus->publish(new ToolListChangedNotification());
        $cursor = $bus->cursor();

        $clear();
        $bus->publish(new PromptListChangedNotification());

        [$found, $next] = $bus->since($cursor);

        $this->assertCount(1, $found);
        $this->assertInstanceOf(PromptListChangedNotification::class, $found[0]);
        $this->assertSame(1, $next);
    }

    #[TestDox('a registry change event becomes a notification on the bus')]
    public function testRegistryEventsArePublished(): void
    {
        $bus = new InMemoryNotificationBus();
        $cursor = $bus->cursor();

        (new PublishingEventDispatcher($bus))->dispatch(new ToolListChangedEvent());

        [$found] = $bus->since($cursor);

        $this->assertCount(1, $found);
        $this->assertInstanceOf(ToolListChangedNotification::class, $found[0]);
    }

    #[TestDox('an event the publisher does not know is passed on untouched')]
    public function testUnknownEventIsPassedThrough(): void
    {
        $bus = new InMemoryNotificationBus();
        $cursor = $bus->cursor();
        $event = new \stdClass();

        $this->assertSame($event, (new PublishingEventDispatcher($bus))->dispatch($event));
        $this->assertSame([], $bus->since($cursor)[0]);
    }

    #[TestDox('an inner dispatcher still sees every event')]
    public function testInnerDispatcherStillRuns(): void
    {
        $seen = [];
        $inner = new class($seen) implements \Psr\EventDispatcher\EventDispatcherInterface {
            /** @param array<int, object> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = $event;

                return $event;
            }
        };

        (new PublishingEventDispatcher(new InMemoryNotificationBus(), $inner))->dispatch(new ToolListChangedEvent());

        $this->assertCount(1, $inner->seen);
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
                if (\is_int($ttl) && $ttl <= 0) {
                    return $this->delete($key);
                }

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
