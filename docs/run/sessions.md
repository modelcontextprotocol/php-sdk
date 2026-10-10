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

## Concurrent Requests

Over HTTP, two requests of one session can run at the same time on different workers: `tools/list` and
`resources/list` sent together on connect, or parallel tool calls from an agent. Each request loads the
session, changes it and writes it back whole, so the last write wins and the other request's changes are
gone: a pending request to the client, the client's answer to one, the client info. Responses do not go
through the session and are not affected.

A session lock serializes the requests of one session. It is off by default; enable it with `setSessionLock()`
and pair it with the store:

```php
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;

// flock() on one file per session, for the workers of one machine
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->setSessionLock(new FileSessionLock(__DIR__ . '/sessions'))
    ->build();
```

Point `FileSessionLock` at the session directory: `FileSessionStore::gc()` then removes the lock files of
expired sessions along with them.

For a store shared across machines, use `SymfonyLockSessionLock` with any
[symfony/lock](https://symfony.com/doc/current/components/lock.html) store (`composer require symfony/lock`):

```php
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SymfonyLockSessionLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$redis = RedisAdapter::createConnection('redis://localhost:6379');

$server = Server::builder()
    ->setSession(new Psr16SessionStore(new Psr16Cache(new RedisAdapter($redis, 'mcp_sessions'))))
    ->setSessionLock(new SymfonyLockSessionLock(new LockFactory(new RedisStore($redis))))
    ->build();
```

The lock is held from before a request reads its session until it saved it, so while its handlers run. It
is released when a handler suspends to wait for the client (elicitation, sampling), and taken briefly on each
poll of the stream that waits for the answer, so the answer's own request can be processed. Parallel tool
calls of one session therefore run one after the other.

A request that cannot get the lock within the timeout (30 seconds by default, the constructors' `$timeout`)
is answered with HTTP `503` and a JSON-RPC error, instead of running on a session another request is about
to overwrite. `SymfonyLockSessionLock` also expires a held lock after `$ttl` (300 seconds by default), so a
worker that dies holding one does not block the session for good.

**Custom Session Locks:**

Implement `SessionLockInterface` for another lock backend. `acquire()` blocks until the lock is held or
throws `SessionLockException` after the backend's timeout; `release()` gives it back.

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
