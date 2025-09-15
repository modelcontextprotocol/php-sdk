<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Discovery;

use Mcp\Capability\Discovery\CachedDiscoverer;
use Mcp\Capability\Discovery\Discoverer;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

class CachedDiscovererTest extends TestCase
{
    public function testCachedDiscovererUsesCacheOnSecondCall(): void
    {
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(null);

        $cache->expects($this->once())
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
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

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
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

        $cache = $this->createMock(CacheInterface::class);

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

        $result1 = $cachedDiscoverer->discover('/path1', ['.'], []);
        $result2 = $cachedDiscoverer->discover('/path2', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result1);
        $this->assertInstanceOf(DiscoveryState::class, $result2);
    }
}
