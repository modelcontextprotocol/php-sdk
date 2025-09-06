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

use Mcp\Capability\Registry;
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
    private const CACHE_TTL = 3600; // 1 hour default TTL

    public function __construct(
        private readonly Discoverer $discoverer,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtl = self::CACHE_TTL,
    ) {
    }

    /**
     * Discover MCP elements in the specified directories with caching.
     *
     * @param string        $basePath    the base path for resolving directories
     * @param array<string> $directories list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs list of directories (relative to base path) to exclude from the scan
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = []): void
    {
        $cacheKey = $this->generateCacheKey($basePath, $directories, $excludeDirs);
        
        // Check if we have cached results
        $cachedResult = $this->cache->get($cacheKey);
        if ($cachedResult !== null) {
            $this->logger->debug('Using cached discovery results', [
                'cache_key' => $cacheKey,
                'base_path' => $basePath,
                'directories' => $directories,
            ]);
            
            // Restore the registry state from cache
            $this->restoreRegistryFromCache($cachedResult);
            return;
        }

        $this->logger->debug('Cache miss, performing fresh discovery', [
            'cache_key' => $cacheKey,
            'base_path' => $basePath,
            'directories' => $directories,
        ]);

        // Perform fresh discovery
        $this->discoverer->discover($basePath, $directories, $excludeDirs);
        
        // Cache the results
        $this->cacheDiscoveryResults($cacheKey);
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
        
        return self::CACHE_PREFIX . md5(serialize($keyData));
    }

    /**
     * Cache the current registry state.
     */
    private function cacheDiscoveryResults(string $cacheKey): void
    {
        try {
            // Get the registry from the discoverer
            $registry = $this->getRegistryFromDiscoverer();
            
            // Extract registry state for caching
            $registryState = $this->extractRegistryState($registry);
            
            // Store in cache
            $this->cache->set($cacheKey, $registryState, $this->cacheTtl);
            
            $this->logger->debug('Cached discovery results', [
                'cache_key' => $cacheKey,
                'ttl' => $this->cacheTtl,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to cache discovery results', [
                'cache_key' => $cacheKey,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Restore registry state from cached data.
     *
     * @param array<string, mixed> $cachedResult
     */
    private function restoreRegistryFromCache(array $cachedResult): void
    {
        try {
            $registry = $this->getRegistryFromDiscoverer();
            $this->restoreRegistryState($registry, $cachedResult);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to restore registry from cache', [
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the registry instance from the discoverer.
     * This uses reflection to access the private registry property.
     */
    private function getRegistryFromDiscoverer(): Registry
    {
        $reflection = new \ReflectionClass($this->discoverer);
        
        if (!$reflection->hasProperty('registry')) {
            throw new \RuntimeException('Discoverer does not have a registry property');
        }
        
        $registryProperty = $reflection->getProperty('registry');
        $registryProperty->setAccessible(true);
        
        return $registryProperty->getValue($this->discoverer);
    }

    /**
     * Extract the current state of the registry for caching.
     *
     * @return array<string, mixed>
     */
    private function extractRegistryState(Registry $registry): array
    {
        // Use reflection to access registry's internal state
        $reflection = new \ReflectionClass($registry);
        
        $state = [];
        
        // Extract tools (only discovered ones, not manual)
        if ($reflection->hasProperty('tools')) {
            $toolsProperty = $reflection->getProperty('tools');
            $toolsProperty->setAccessible(true);
            $tools = $toolsProperty->getValue($registry);
            $state['tools'] = array_filter($tools, fn($tool) => !$tool->isManual);
        }
        
        // Extract resources (only discovered ones, not manual)
        if ($reflection->hasProperty('resources')) {
            $resourcesProperty = $reflection->getProperty('resources');
            $resourcesProperty->setAccessible(true);
            $resources = $resourcesProperty->getValue($registry);
            $state['resources'] = array_filter($resources, fn($resource) => !$resource->isManual);
        }
        
        // Extract prompts (only discovered ones, not manual)
        if ($reflection->hasProperty('prompts')) {
            $promptsProperty = $reflection->getProperty('prompts');
            $promptsProperty->setAccessible(true);
            $prompts = $promptsProperty->getValue($registry);
            $state['prompts'] = array_filter($prompts, fn($prompt) => !$prompt->isManual);
        }
        
        // Extract resource templates (only discovered ones, not manual)
        if ($reflection->hasProperty('resourceTemplates')) {
            $resourceTemplatesProperty = $reflection->getProperty('resourceTemplates');
            $resourceTemplatesProperty->setAccessible(true);
            $resourceTemplates = $resourceTemplatesProperty->getValue($registry);
            $state['resourceTemplates'] = array_filter($resourceTemplates, fn($template) => !$template->isManual);
        }
        
        return $state;
    }

    /**
     * Restore registry state from cached data.
     *
     * @param array<string, mixed> $cachedState
     */
    private function restoreRegistryState(Registry $registry, array $cachedState): void
    {
        $reflection = new \ReflectionClass($registry);
        
        // Restore tools
        if (isset($cachedState['tools']) && $reflection->hasProperty('tools')) {
            $toolsProperty = $reflection->getProperty('tools');
            $toolsProperty->setAccessible(true);
            $toolsProperty->setValue($registry, $cachedState['tools']);
        }
        
        // Restore resources
        if (isset($cachedState['resources']) && $reflection->hasProperty('resources')) {
            $resourcesProperty = $reflection->getProperty('resources');
            $resourcesProperty->setAccessible(true);
            $resourcesProperty->setValue($registry, $cachedState['resources']);
        }
        
        // Restore prompts
        if (isset($cachedState['prompts']) && $reflection->hasProperty('prompts')) {
            $promptsProperty = $reflection->getProperty('prompts');
            $promptsProperty->setAccessible(true);
            $promptsProperty->setValue($registry, $cachedState['prompts']);
        }
        
        // Restore resource templates
        if (isset($cachedState['resourceTemplates']) && $reflection->hasProperty('resourceTemplates')) {
            $resourceTemplatesProperty = $reflection->getProperty('resourceTemplates');
            $resourceTemplatesProperty->setAccessible(true);
            $resourceTemplatesProperty->setValue($registry, $cachedState['resourceTemplates']);
        }
    }

    /**
     * Clear the discovery cache.
     * Useful for development or when files change.
     */
    public function clearCache(): void
    {
        // This is a simple implementation that clears all discovery cache entries
        // In a more sophisticated implementation, we might want to track cache keys
        // and clear them selectively
        
        $this->cache->clear();
        $this->logger->info('Discovery cache cleared');
    }
}