# Session Management

> Sessions belong to the handshake era. Protocol revision `2026-07-28` removed them, along with
> `Mcp-Session-Id`, because every request carries what is needed to answer it — see
> [The 2026-07-28 lifecycle](../protocol-versions.md). One server serves both, so the configuration below still
> applies to the handshake-era clients it answers.

Configure session storage and lifecycle. By default, the SDK uses `InMemorySessionStore`:

```php
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Psr16SessionStore;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\RedisAdapter;

// Override with file-based storage
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->build();

// Override with in-memory storage and custom TTL
$server = Server::builder()
    ->setSession(new InMemorySessionStore(3600))
    ->build();

// Override with PSR-16 cache-based storage
// Requires psr/simple-cache and symfony/cache (or any other PSR-16 implementation)
// composer require psr/simple-cache symfony/cache
$redisAdapter = new RedisAdapter(
    RedisAdapter::createConnection('redis://localhost:6379'),
    'mcp_sessions'
);

$server = Server::builder()
    ->setSession(new Psr16SessionStore(
        cache: new Psr16Cache($redisAdapter),
        prefix: 'mcp-',
        ttl: 3600
    ))
    ->build();
```

## Sessions over HTTP

With HTTP, PHP often serves each request in a different process (e.g. PHP-FPM workers).
`InMemorySessionStore` keeps sessions in the memory of one process, so the next request
doesn't find them. Use a store that all processes share, like `FileSessionStore` on a shared
directory or `Psr16SessionStore` with a shared cache such as Redis.

`FileSessionStore` suits a single host. Once requests are spread over several hosts, or sessions number in the
tens of thousands, use `Psr16SessionStore` with Redis or a similar shared cache: it is shared across hosts,
expires sessions on its own and needs no garbage collection (see below). A network-mounted directory adds latency and
makes session availability depend on the shared filesystem.

## Garbage Collection Configuration

The SDK periodically runs garbage collection to clean up expired sessions, similar to PHP's native
`session.gc_probability` and `session.gc_divisor` settings. The probability that GC runs on any given
request is `gcProbability / gcDivisor`.

```php
// Default: 1/100 (1% chance per request)
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->build();

// Higher frequency: 1/10 (10% chance per request)
$server = Server::builder()
    ->setSession(
        new FileSessionStore(__DIR__ . '/sessions'),
        gcProbability: 1,
        gcDivisor: 10,
    )
    ->build();

// Run GC on every request
$server = Server::builder()
    ->setSession(gcProbability: 1, gcDivisor: 1)
    ->build();

// Disable GC entirely (e.g. when using an external cleanup process)
$server = Server::builder()
    ->setSession(gcProbability: 0)
    ->build();
```

**Running GC Out of Band:**

GC runs inside a request, before the server handles the message. `FileSessionStore::gc()` scans every file in
the session directory, so its cost grows with the number of sessions. On a busy server, a random request then
waits for that scan.

In that case, disable GC in the server and call `gc()` from a cron job or a console command. Use the same
directory and TTL as the server:

```php
// bin/session-gc.php, e.g. run every 10 minutes from cron
use Mcp\Server\Session\FileSessionStore;

require __DIR__ . '/../vendor/autoload.php';

$store = new FileSessionStore(__DIR__ . '/../sessions', ttl: 3600);
$deleted = $store->gc(); // returns the IDs of the deleted sessions

echo count($deleted) . " expired sessions deleted\n";
```

```php
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions', ttl: 3600), gcProbability: 0)
    ->build();
```

`Psr16SessionStore` doesn't scan anything: it stores each session with the TTL, and its `gc()` does nothing.
With a cache like Redis, the cache expires the keys itself.

**Parameters:**

- `$gcProbability` (int): The numerator of the GC probability fraction (default: `1`). Set to `0` to disable GC.
- `$gcDivisor` (int): The denominator of the GC probability fraction (default: `100`). Must be >= 1.

> **Note**: When providing a custom `SessionManagerInterface` via the `$sessionManager` parameter,
> the `gcProbability` and `gcDivisor` settings are ignored — you control GC behavior in your own implementation.

**Available Session Stores:**

- `InMemorySessionStore`: Fast in-memory storage (default)
- `FileSessionStore`: Persistent file-based storage
- `Psr16SessionStore`: PSR-16 compliant cache-based storage

**Custom Session Stores:**

Implement `SessionStoreInterface` to create custom session storage:

```php
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

class RedisSessionStore implements SessionStoreInterface
{
    public function __construct(private $redis, private int $ttl = 3600) {}

    public function exists(Uuid $id): bool
    {
        return $this->redis->exists($id->toRfc4122());
    }

    public function read(Uuid $sessionId): string|false
    {
        $data = $this->redis->get($sessionId->toRfc4122());
        return $data !== false ? $data : false;
    }

    public function write(Uuid $sessionId, string $data): bool
    {
        return $this->redis->setex($sessionId->toRfc4122(), $this->ttl, $data);
    }

    public function destroy(Uuid $sessionId): bool
    {
        return $this->redis->del($sessionId->toRfc4122()) > 0;
    }

    public function gc(): array
    {
        // Redis handles TTL automatically
        return [];
    }
}
```
