<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Capability\Discovery;

use Mcp\Server\Capability\Discovery\CachedDiscoverer;
use Mcp\Server\Capability\Discovery\DiscovererInterface;
use Mcp\Server\Capability\Discovery\DiscoveryState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CachedDiscovererTest extends TestCase
{
    /**
     * @return iterable<string, array{CacheInterface|CacheItemPoolInterface}>
     */
    public static function provideCaches(): iterable
    {
        yield 'PSR-6' => [new ArrayAdapter()];
        yield 'PSR-16' => [self::arrayCache()];
    }

    #[DataProvider('provideCaches')]
    public function testCachedResultsAreReturnedOnSecondCall(CacheInterface|CacheItemPoolInterface $cache): void
    {
        $state = new DiscoveryState();
        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(1, $state), $cache, new NullLogger());

        $this->assertEquals($state, $cachedDiscoverer->discover('/test/path', ['.']));
        $this->assertEquals($state, $cachedDiscoverer->discover('/test/path', ['.']));
    }

    #[DataProvider('provideCaches')]
    public function testDifferentArgumentsDoNotShareACacheEntry(CacheInterface|CacheItemPoolInterface $cache): void
    {
        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(5), $cache, new NullLogger());

        $cachedDiscoverer->discover('/test/path', ['.'], [], ['*.php']);
        $cachedDiscoverer->discover('/other/path', ['.'], [], ['*.php']);
        $cachedDiscoverer->discover('/test/path', ['src'], [], ['*.php']);
        $cachedDiscoverer->discover('/test/path', ['.'], ['vendor'], ['*.php']);
        $cachedDiscoverer->discover('/test/path', ['.'], [], ['*Tool.php']);
    }

    #[DataProvider('provideCaches')]
    public function testClearCacheInvalidatesEveryEntryAcrossInstances(CacheInterface|CacheItemPoolInterface $cache): void
    {
        $discoverer = $this->discoverer(4);

        $first = new CachedDiscoverer($discoverer, $cache, new NullLogger());
        $first->discover('/first', ['.']);
        $first->discover('/second', ['.']);

        // Another instance, as a cache:clear command in a separate process would be.
        (new CachedDiscoverer($discoverer, $cache, new NullLogger()))->clearCache();

        $first->discover('/first', ['.']);
        $first->discover('/second', ['.']);
    }

    public function testClearCacheKeepsForeignEntriesOfAPsr6Pool(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('foreign')->set('kept'));

        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(1), $pool, new NullLogger());
        $cachedDiscoverer->discover('/test/path', ['.']);
        $cachedDiscoverer->clearCache();

        $this->assertSame('kept', $pool->getItem('foreign')->get());
    }

    public function testClearCacheKeepsForeignEntriesOfAPsr16Cache(): void
    {
        $cache = self::arrayCache();
        $cache->set('foreign', 'kept');

        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(1), $cache, new NullLogger());
        $cachedDiscoverer->discover('/test/path', ['.']);
        $cachedDiscoverer->clearCache();

        $this->assertSame('kept', $cache->get('foreign'));
    }

    public function testEvictedGenerationDoesNotServeStaleEntries(): void
    {
        $pool = new ArrayAdapter();
        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(2), $pool, new NullLogger());

        $cachedDiscoverer->discover('/test/path', ['.']);
        $pool->deleteItem('mcp_discovery_v2_generation');
        $cachedDiscoverer->discover('/test/path', ['.']);
    }

    public function testEntryOfARenamedClassIsDiscoveredAgain(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(static fn (string $key): mixed => 'mcp_discovery_v2_generation' === $key
            ? 'generation'
            : unserialize('O:39:"Mcp\Capability\Discovery\DiscoveryState":0:{}'));

        $cachedDiscoverer = new CachedDiscoverer($this->discoverer(1), $cache, new NullLogger());

        $this->assertInstanceOf(DiscoveryState::class, $cachedDiscoverer->discover('/test/path', ['.']));
    }

    public function testDiscoveryRunningDuringClearCacheIsNotServedAfterwards(): void
    {
        $pool = new ArrayAdapter();
        $otherProcess = new CachedDiscoverer($this->createStub(DiscovererInterface::class), $pool, new NullLogger());
        $calls = 0;

        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->exactly(2))
            ->method('discover')
            ->willReturnCallback(static function () use ($otherProcess, &$calls): DiscoveryState {
                if (1 === ++$calls) {
                    $otherProcess->clearCache();
                }

                return new DiscoveryState();
            });

        $cachedDiscoverer = new CachedDiscoverer($discoverer, $pool, new NullLogger());

        $cachedDiscoverer->discover('/test/path', ['.']);
        $cachedDiscoverer->discover('/test/path', ['.']);
    }

    private function discoverer(int $expectedCalls, DiscoveryState $state = new DiscoveryState()): DiscovererInterface
    {
        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->exactly($expectedCalls))
            ->method('discover')
            ->willReturn($state);

        return $discoverer;
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
