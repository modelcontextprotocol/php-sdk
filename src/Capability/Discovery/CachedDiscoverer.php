<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Cached decorator for the Discoverer class.
 *
 * This decorator caches the results of file system operations and reflection
 * to improve performance when discovery is called multiple times.
 *
 * @author Xentixar <xentixar@gmail.com>
 */
class CachedDiscoverer
{
    private const CACHE_PREFIX = 'mcp_discovery_';

    public function __construct(
        private readonly Discoverer $discoverer,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Discover MCP elements in the specified directories with caching.
     *
     * @param string        $basePath    the base path for resolving directories
     * @param array<string> $directories list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs list of directories (relative to base path) to exclude from the scan
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = []): DiscoveryState
    {
        $cacheKey = $this->generateCacheKey($basePath, $directories, $excludeDirs);

        $cachedResult = $this->cache->get($cacheKey);
        if (null !== $cachedResult) {
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

        $discoveryState = $this->discoverer->discover($basePath, $directories, $excludeDirs);

        $this->cache->set($cacheKey, $discoveryState);

        return $discoveryState;
    }

    /**
     * Generate a cache key based on discovery parameters.
     *
     * @param array<string> $directories
     * @param array<string> $excludeDirs
     */
    private function generateCacheKey(string $basePath, array $directories, array $excludeDirs): string
    {
        $keyData = [
            'base_path' => $basePath,
            'directories' => $directories,
            'exclude_dirs' => $excludeDirs,
        ];

        return self::CACHE_PREFIX.md5(serialize($keyData));
    }

    /**
     * Clear the discovery cache.
     * Useful for development or when files change.
     */
    public function clearCache(): void
    {
        $this->cache->clear();
        $this->logger->info('Discovery cache cleared');
    }
}
