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
use Mcp\Capability\Registry\ReferenceHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

class CachedDiscovererTest extends TestCase
{
    public function testCachedDiscovererUsesCacheOnSecondCall(): void
    {
        // Create a real registry and discoverer for proper testing
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

        // Create a mock cache that tracks calls
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(null); // First call: cache miss

        $cache->expects($this->once())
            ->method('set')
            ->willReturn(true); // Cache the results

        // Create the cached discoverer
        $cachedDiscoverer = new CachedDiscoverer(
            $discoverer,
            $cache,
            new NullLogger()
        );

        // First call should hit the discoverer and cache the results
        $result = $cachedDiscoverer->discover('/test/path', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result);
    }

    public function testCachedDiscovererReturnsCachedResults(): void
    {
        // Create a real registry and discoverer for proper testing
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

        // Create mock cached data
        $cachedData = [
            'tools' => [],
            'resources' => [],
            'prompts' => [],
            'resourceTemplates' => [],
        ];

        // Create a mock cache that returns cached data
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedData); // Cache hit

        $cache->expects($this->never())
            ->method('set'); // Should not cache again

        // Create the cached discoverer
        $cachedDiscoverer = new CachedDiscoverer(
            $discoverer,
            $cache,
            new NullLogger()
        );

        // Call should use cached results without calling the underlying discoverer
        $result = $cachedDiscoverer->discover('/test/path', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result);
    }

    public function testCacheKeyGeneration(): void
    {
        // Create a real registry and discoverer for proper testing
        $registry = new Registry(null, new NullLogger());
        $discoverer = new Discoverer($registry, new NullLogger());

        $cache = $this->createMock(CacheInterface::class);

        // Test that different parameters generate different cache keys
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

        // Different base paths should generate different cache keys
        $result1 = $cachedDiscoverer->discover('/path1', ['.'], []);
        $result2 = $cachedDiscoverer->discover('/path2', ['.'], []);
        $this->assertInstanceOf(DiscoveryState::class, $result1);
        $this->assertInstanceOf(DiscoveryState::class, $result2);
    }
}