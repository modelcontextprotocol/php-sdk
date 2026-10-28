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
use Mcp\Server\Session\Psr6SessionStore;
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

// Override with PSR-6 cache-based storage
// Requires symfony/cache (or any other PSR-6 implementation)
// composer require symfony/cache
$redisAdapter = new RedisAdapter(
    RedisAdapter::createConnection('redis://localhost:6379'),
    'mcp_sessions'
);

$server = Server::builder()
    ->setSession(new Psr6SessionStore(
        cache: $redisAdapter,
        prefix: 'mcp-',
        ttl: 3600
    ))
    ->build();

// Override with PSR-16 cache-based storage, which takes the same arguments
// Requires psr/simple-cache and a PSR-16 implementation
$server = Server::builder()
    ->setSession(new Psr16SessionStore(new Psr16Cache($redisAdapter)))
    ->build();
```

## Sessions over HTTP

With HTTP, PHP often serves each request in a different process (e.g. PHP-FPM workers).
`InMemorySessionStore` keeps sessions in the memory of one process, so the next request
doesn't find them. Use a store that all processes share, like `FileSessionStore` on a shared
directory or `Psr6SessionStore` (or `Psr16SessionStore`) with a shared cache such as Redis.

`FileSessionStore` suits a single host. Once requests are spread over several hosts, or sessions number in the
tens of thousands, use `Psr6SessionStore` or `Psr16SessionStore` with Redis or a similar shared cache: it is shared
across hosts, expires sessions on its own and needs no garbage collection (see below). A network-mounted directory adds
latency and makes session availability depend on the shared filesystem.

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

`Psr6SessionStore` and `Psr16SessionStore` don't scan anything. They store each session with the TTL, and their `gc()`
does nothing.
With a cache like Redis, the cache expires the keys itself.

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
expired sessions along with them. It never removes the file of a lock a request still holds.

For a store shared across machines, use `SymfonyLockSessionLock` with any
[symfony/lock](https://symfony.com/doc/current/components/lock.html) store (`composer require symfony/lock`):

```php
use Mcp\Server\Session\Psr6SessionStore;
use Mcp\Server\Session\SymfonyLockSessionLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

$redis = RedisAdapter::createConnection('redis://localhost:6379');

$server = Server::builder()
    ->setSession(new Psr6SessionStore(new RedisAdapter($redis, 'mcp_sessions')))
    ->setSessionLock(new SymfonyLockSessionLock(new LockFactory(new RedisStore($redis))))
    ->build();
```

The lock is held from before a request reads its session until it saved it, so while its handlers run. It
is released when a handler suspends to wait for the client (elicitation, sampling), so the answer's own
request can be processed. The stream that waits for the answer takes the lock briefly on each poll, and only
when it is free; otherwise it tries again on its next poll. Parallel tool calls of one session therefore run
one after the other.

A request that cannot get the lock within the timeout (30 seconds by default, the constructors' `$timeout`)
is answered with HTTP `503` and a JSON-RPC error, instead of running on a session another request is about
to overwrite. A lock backend that fails, e.g. a Redis that refuses connections, is answered with `503` too,
but at once, with the error message `Session lock is unavailable.`, and logged as an error with its cause;
a timeout is only logged as a warning. A handler that resumed after the client answered gets an error when the lock cannot be had to
store its next request or notification: the `ClientGateway` method throws a `ClientException`, and the
message does not reach the client.

`SymfonyLockSessionLock` expires a held lock after `$ttl` (300 seconds by default), so a worker that dies
holding one does not block the session for good. The lease is not refreshed while a handler runs, so `$ttl`
must be longer than your longest handler runs; otherwise another request can take the lock before the first
one saved the session. A fatal error in a handler (memory limit, `max_execution_time`) skips the normal
release, so `SymfonyLockSessionLock` also releases its locks from a shutdown function; `FileSessionLock` needs
none, as the operating system drops a `flock()` with the process.

**Custom Session Locks:**

Implement `SessionLockInterface` for another lock backend. `acquire()` blocks until the lock is held or
throws `SessionLockTimeoutException` after the backend's timeout, and `SessionLockException` when the backend
fails; `tryAcquire()` takes the lock only if it is free
right now and returns `false` otherwise; `release()` gives it back.

**Available Session Stores:**

- `InMemorySessionStore`: Fast in-memory storage (default)
- `FileSessionStore`: Persistent file-based storage
- `Psr6SessionStore`: PSR-6 compliant cache-based storage
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
