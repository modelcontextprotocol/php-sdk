# Session Management

> Sessions belong to the handshake era. Protocol revision `2026-07-28` removed them, along with
> `Mcp-Session-Id`, because every request carries what is needed to answer it — see
> [The 2026-07-28 lifecycle](../protocol-versions.md). One server serves both, so the configuration below still
> applies to the handshake-era clients it answers.

Configure session storage and lifecycle. By default, the SDK uses `InMemorySessionStore`:

```php
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

## Concurrent Requests

A client can send several requests of one session at the same time, for example parallel tool calls. Each
request loads the session, changes it, and saves the keys it changed. When two requests change different keys,
both changes are kept. When they change the same key, for example a counter in a tool handler, the request that
saves last wins and the other change is lost.

To prevent this, configure a session lock. A request then holds the lock from loading its session to saving it,
and the other requests of that session wait for it:

```php
use Mcp\Server\Session\SymfonySessionLock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

// composer require symfony/lock
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->setSessionLock(new SymfonySessionLock(new LockFactory(new FlockStore())))
    ->build();
```

All workers must share the lock store: `FlockStore` works for workers on one host, use e.g. a `RedisStore`
when the workers run on several hosts.

- `$timeout` (float): Seconds a request waits for the lock (default: `30.0`). After that, it fails with a
  `TimeoutException` and the client gets an internal error. Use a value above the duration of your longest
  handler.
- `$ttl` (float): Seconds after which the store expires a lock that was never released, e.g. by a crashed
  worker (default: `300.0`).

A request that waits on the client, for example for an elicitation or a sampling result, releases the lock
while it waits. The lock is off by default, and the stdio transport does not need it, because it serves one
client in one process. Implement `SessionLockInterface` to use another locking mechanism.
