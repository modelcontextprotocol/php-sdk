# Discovery Caching

The MCP PHP SDK now supports caching of discovery results to improve performance, especially in development environments where the server is restarted frequently.

## Overview

The discovery system scans PHP files and uses reflection to find MCP attributes (tools, resources, prompts, etc.). This process can be expensive when performed repeatedly. The caching system stores the results of this discovery process and reuses them on subsequent calls.

## Performance Benefits

Based on performance testing, the caching system provides significant speed improvements:

- **First call (cache miss)**: ~3x faster than uncached discovery
- **Subsequent calls (cache hit)**: **72x faster** than uncached discovery

## Usage

### Basic Usage

```php
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

Server::make()
    ->withServerInfo('My Server', '1.0.0', 'Server with cached discovery')
    ->withDiscovery(__DIR__, ['.'])
    ->withCache(new Psr16Cache(new ArrayAdapter())) // Enable caching
    ->build()
    ->connect(new StdioTransport());
```

### Using Different Cache Implementations

The caching system uses PSR-16 SimpleCache, so you can use any compatible cache implementation:

```php
// Array cache (in-memory, good for development)
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new ArrayAdapter());

// Redis cache (good for production)
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$cache = new Psr16Cache(new RedisAdapter($redis));

// Filesystem cache (good for persistent caching)
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new FilesystemAdapter('mcp_discovery', 0, '/tmp/mcp_cache'));
```

## How It Works

1. **Cache Key Generation**: A unique cache key is generated based on:
   - Base path for discovery
   - Directories to scan
   - Directories to exclude

2. **Cache Miss**: When no cached data is found:
   - The underlying `Discoverer` performs fresh discovery
   - Results are stored in the cache with a configurable TTL (default: 1 hour)

3. **Cache Hit**: When cached data is found:
   - The registry is restored from cached data
   - No file system operations or reflection are performed

## Cache Invalidation

The cache is automatically invalidated when:
- The cache TTL expires (default: 1 hour)
- The cache implementation handles expiration

For development, you may want to use a shorter TTL or clear the cache manually when files change.

## Configuration

### Cache TTL

You can configure the cache TTL when creating a `CachedDiscoverer`:

```php
use Mcp\Capability\Discovery\CachedDiscoverer;

$cachedDiscoverer = new CachedDiscoverer(
    $discoverer,
    $cache,
    $logger,
    1800 // 30 minutes TTL
);
```

### Manual Cache Clearing

```php
$cachedDiscoverer->clearCache();
```

## Best Practices

1. **Development**: Use `ArrayAdapter` for fast, in-memory caching
2. **Production**: Use persistent cache implementations like Redis or filesystem
3. **CI/CD**: Consider clearing cache between builds
4. **Monitoring**: Monitor cache hit rates to ensure effectiveness

## Example

See the `examples/10-cached-discovery-stdio/` directory for a complete working example of cached discovery.

## Implementation Details

The caching system uses the decorator pattern:
- `CachedDiscoverer` wraps the existing `Discoverer` class
- Uses reflection to extract and restore registry state
- Only caches discovered elements (not manually registered ones)
- Maintains backward compatibility - works with or without cache