<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Discovery;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Cached decorator for the Discoverer class.
 *
 * This decorator caches the results of file system operations and reflection
 * to improve performance when discovery is called multiple times.
 *
 * @internal
 *
 * @author Xentixar <xentixar@gmail.com>
 */
final class CachedDiscoverer implements DiscovererInterface
{
    // Versioned, since an entry holds serialized SDK classes that a release can rename.
    private const CACHE_PREFIX = 'mcp_discovery_v2_';
    private const GENERATION_KEY = self::CACHE_PREFIX.'generation';

    public function __construct(
        private readonly DiscovererInterface $discoverer,
        private readonly CacheInterface|CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Discover MCP elements in the specified directories with caching.
     *
     * @param string        $basePath     the base path for resolving directories
     * @param array<string> $directories  list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs  list of directories (relative to base path) to exclude from the scan
     * @param array<string> $namePatterns list of file name patterns for the scan. Compatible with Finder->name()
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState
    {
        $cacheKey = $this->generateCacheKey($this->generation(), $basePath, $directories, $excludeDirs, $namePatterns);

        $cachedResult = $this->read($cacheKey);

        if ($cachedResult instanceof DiscoveryState) {
            $this->logger->debug('Using cached discovery results', [
                'cache_key' => $cacheKey,
                'base_path' => $basePath,
                'directories' => $directories,
            ]);

            return $cachedResult;
        }

        $this->logger->debug('Cache miss, performing fresh discovery', [
            'cache_key' => $cacheKey,
            'base_path' => $basePath,
            'directories' => $directories,
        ]);

        $discoveryState = $this->discoverer->discover($basePath, $directories, $excludeDirs, $namePatterns);

        $this->write($cacheKey, $discoveryState);

        return $discoveryState;
    }

    /**
     * Generate a cache key based on discovery parameters.
     *
     * @param array<string> $directories
     * @param array<string> $excludeDirs
     * @param array<string> $namePatterns
     */
    private function generateCacheKey(string $generation, string $basePath, array $directories, array $excludeDirs, array $namePatterns): string
    {
        $keyData = [
            'generation' => $generation,
            'base_path' => $basePath,
            'directories' => $directories,
            'exclude_dirs' => $excludeDirs,
            'name_patterns' => $namePatterns,
        ];

        return self::CACHE_PREFIX.md5(serialize($keyData));
    }

    /**
     * Clear the discovery cache, leaving other entries of a shared cache in place.
     * Useful for development or when files change.
     *
     * Cleared entries become unreachable and stay until the cache expires or evicts them.
     */
    public function clearCache(): void
    {
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->deleteItem(self::GENERATION_KEY);
        } else {
            $this->cache->delete(self::GENERATION_KEY);
        }

        $this->logger->info('Discovery cache cleared');
    }

    private function generation(): string
    {
        $generation = $this->read(self::GENERATION_KEY);

        if (\is_string($generation)) {
            return $generation;
        }

        $generation = bin2hex(random_bytes(8));
        $this->write(self::GENERATION_KEY, $generation);

        return $generation;
    }

    private function read(string $key): mixed
    {
        if ($this->cache instanceof CacheItemPoolInterface) {
            return $this->cache->getItem($key)->get();
        }

        return $this->cache->get($key);
    }

    private function write(string $key, mixed $value): void
    {
        if ($this->cache instanceof CacheItemPoolInterface) {
            $this->cache->save($this->cache->getItem($key)->set($value));

            return;
        }

        $this->cache->set($key, $value);
    }
}
