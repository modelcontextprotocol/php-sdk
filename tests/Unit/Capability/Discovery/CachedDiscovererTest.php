<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscovererInterface;
use Mcp\Capability\Discovery\DiscoveryState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CachedDiscovererTest extends TestCase
{
    public function testCachedDiscovererUsesCacheOnSecondCall(): void
    {
        $discoverer = new Discoverer();

        $cache = $this->createMock(CacheInterface::class);
        // The entry and the index of keys clearCache() deletes.
        $cache->expects($this->exactly(2))
            ->method('get')
            ->willReturn(null);

        $cache->expects($this->exactly(2))
            ->method('set')
            ->willReturn(true);

        $cachedDiscoverer = new CachedDiscoverer(
            $discoverer,
            $cache,
            new NullLogger()
        );

        $result = $cachedDiscoverer->discover('/test/path', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result);
    }

    public function testCachedDiscovererReturnsCachedResults(): void
    {
        $discoverer = new Discoverer();

        $cache = $this->createMock(CacheInterface::class);
        $cachedState = new DiscoveryState();
        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedState);

        $cache->expects($this->never())
            ->method('set');

        $cachedDiscoverer = new CachedDiscoverer(
            $discoverer,
            $cache,
            new NullLogger()
        );

        $result = $cachedDiscoverer->discover('/test/path', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result);
    }

    public function testCacheKeyGeneration(): void
    {
        $discoverer = new Discoverer();

        $cache = $this->createMock(CacheInterface::class);

        $cache->expects($this->exactly(4))
            ->method('get')
            ->willReturn(null);

        $cache->expects($this->exactly(4))
            ->method('set')
            ->willReturn(true);

        $cachedDiscoverer = new CachedDiscoverer(
            $discoverer,
            $cache,
            new NullLogger()
        );

        $result1 = $cachedDiscoverer->discover('/path1', ['.'], []);
        $result2 = $cachedDiscoverer->discover('/path2', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result1);
        $this->assertInstanceOf(DiscoveryState::class, $result2);
    }

    public function testCachedDiscovererAcceptsAPsr6Pool(): void
    {
        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->once())
            ->method('discover')
            ->willReturn(new DiscoveryState());

        $pool = new ArrayAdapter();
        $cachedDiscoverer = new CachedDiscoverer($discoverer, $pool, new NullLogger());

        $this->assertEquals(new DiscoveryState(), $cachedDiscoverer->discover('/test/path', ['.'], []));
        $this->assertEquals(new DiscoveryState(), $cachedDiscoverer->discover('/test/path', ['.'], []));

        $cachedDiscoverer->clearCache();
        $this->assertSame([], $pool->getValues());
    }

    public function testDifferentNamePatternsDoNotShareACacheEntry(): void
    {
        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->exactly(2))
            ->method('discover')
            ->willReturn(new DiscoveryState());

        $cachedDiscoverer = new CachedDiscoverer($discoverer, new ArrayAdapter(), new NullLogger());

        $cachedDiscoverer->discover('/test/path', ['.'], [], ['*.php']);
        $cachedDiscoverer->discover('/test/path', ['.'], [], ['*Tool.php']);
    }

    public function testClearCacheKeepsForeignEntriesOfAPsr6Pool(): void
    {
        $pool = new ArrayAdapter();
        $pool->save($pool->getItem('foreign')->set('kept'));

        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->exactly(2))
            ->method('discover')
            ->willReturn(new DiscoveryState());

        (new CachedDiscoverer($discoverer, $pool, new NullLogger()))->discover('/test/path', ['.']);
        // Another instance, as a cache:clear command in a separate process would be.
        (new CachedDiscoverer($discoverer, $pool, new NullLogger()))->clearCache();
        (new CachedDiscoverer($discoverer, $pool, new NullLogger()))->discover('/test/path', ['.']);

        $this->assertSame('kept', $pool->getItem('foreign')->get());
    }

    public function testClearCacheKeepsForeignEntriesOfAPsr16Cache(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());
        $cache->set('foreign', 'kept');

        $discoverer = $this->createMock(DiscovererInterface::class);
        $discoverer->expects($this->exactly(2))
            ->method('discover')
            ->willReturn(new DiscoveryState());

        (new CachedDiscoverer($discoverer, $cache, new NullLogger()))->discover('/test/path', ['.']);
        (new CachedDiscoverer($discoverer, $cache, new NullLogger()))->clearCache();
        (new CachedDiscoverer($discoverer, $cache, new NullLogger()))->discover('/test/path', ['.']);

        $this->assertSame('kept', $cache->get('foreign'));
    }
}
