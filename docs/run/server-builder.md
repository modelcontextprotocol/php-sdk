# Server builder

The server `Builder` is a fluent builder class that simplifies the creation and configuration of an MCP server instance.
It provides methods for setting server information, configuring discovery, registering capabilities, and customizing
various aspects of the server behavior.

## Basic Usage

There are two ways to obtain a server builder instance:

### Method 1: Static Builder Method (Recommended)

```php
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->build();
```

### Method 2: Direct Instantiation

```php
use Mcp\Server\Builder;

$server = (new Builder())
    ->setServerInfo('My MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->build();
```

Both methods return a `Builder` instance that you can configure with fluent methods. The `build()` method returns the
final `Server` instance ready for use.

## Server Configuration

### Server Information

Set the server's identity with name, version, and optional description:

```php
use Mcp\Schema\Icon;
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo(
        name: 'Calculator Server',
        version: '1.2.0',
        description: 'Advanced mathematical calculations',
        icons: [new Icon('https://example.com/icon.png', 'image/png', ['64x64'])],
        websiteUrl: 'https://example.com',
    );
```

**Parameters:**

- `$name` (string): The server name
- `$version` (string): Version string (semantic versioning recommended)
- `$description` (string|null): Optional description
- `$icons` (Icon[]|null): Optional array of server icons
- `$websiteUrl` (string|null): Optional server website URL
- `$title` (string|null): Optional human-readable display title, distinct from `$name`

### Pagination Limit

Configure the maximum number of items returned in paginated responses:

```php
$server = Server::builder()
    ->setPaginationLimit(100); // Default: 50
```

### Instructions

Provide hints to help AI models understand how to use your server:

```php
$server = Server::builder()
    ->setInstructions('This calculator supports basic arithmetic operations. Use the calculate tool for math operations and check the config resource for current settings.');
```

### Protocol Version

By default the server negotiates the protocol revision with each client during the `initialize` handshake, and you do
not need to configure anything. `setProtocolVersion()` pins that handshake to exactly one revision instead:

```php
use Mcp\Schema\Enum\ProtocolVersion;

$server = Server::builder()
    ->setProtocolVersion(ProtocolVersion::V2025_06_18);
```

See [Protocol versions](../protocol-versions.md#negotiating-in-the-handshake-era) for how negotiation resolves and
what pinning changes. It only pins the handshake era; to narrow or remove what the modern leg answers for, use
`setModernVersions()` / `withoutModernEra()` — see
[Serving both eras](protocol-eras.md#serving-one-era-only).

## Modern-Era Options

`build()` returns a server that answers both [protocol eras](../protocol-versions.md), and none of the following is
required to serve either one. Each knob is covered in depth elsewhere:
[Asking for input](../handlers/input-required.md) for the first two,
[Caching](caching.md), [Subscriptions](subscriptions.md), and
[Serving both eras](protocol-eras.md) for the last.

```php
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Stateless\OriginatingRequestBinder;
use Mcp\Server\Subscription\Psr16NotificationBus;
use Mcp\Server\Wire\CachePolicy;

$server = Server::builder()
    // Signs the state a multi round-trip request carries through the client.
    // The same key must reach every process that might serve the retry.
    // The key needs at least 32 bytes, otherwise build() throws. TTL in seconds, default 600.
    // A list of keys rotates them: the first signs, every key verifies.
    // Without a key, the server refuses every state the client sends back.
    // The optional binder ties each state to the principal and the call it was
    // minted for, so a client cannot echo it on another call or under another token.
    ->setRequestState($_ENV['MCP_REQUEST_STATE_KEY'], ttl: 600, binder: new OriginatingRequestBinder())

    // Bounds the input-required shim, which fulfils an `InputRequiredResult`
    // over a handshake-era connection. `withoutInputRequiredShim()` turns it off.
    // Defaults to 8 rounds and a 600 second timeout for each input request.
    ->setInputRequiredLimits(maxRounds: 4, roundTimeout: 120)

    // Caching hints stamped on cacheable results. Defaults to `ttlMs: 0, private`.
    ->setCachePolicy(CachePolicy::default(30_000)->withMethod('tools/list', 3_600_000, CacheScope::Public))

    // Delivery for `subscriptions/listen`, and how long such a stream is held.
    // The lifetime defaults to 30 seconds; 0 keeps the stream open until the client or the runtime ends it.
    ->setNotificationBus(new Psr16NotificationBus($cache))
    ->setSubscriptionLifetime(0)

    // Narrow the modern leg, or drop it entirely.
    ->setModernVersions([ProtocolVersion::V2026_07_28])
    ->build();
```

`buildStateless()` returns the modern dispatcher alone, for an endpoint that serves no handshake-era traffic at all.
Its requests are checked against the SEP-2243 standard headers — that `Mcp-Method`, `Mcp-Name` and `Mcp-Param-*` agree
with the body they travel with. `build()` uses the same dispatcher for its modern leg, so the check applies there too.
`setHeaderValidator(false)` turns that check off, which is needed only when the dispatcher is served by a transport
that has no header layer to check.

The modern leg answers `server/discover` with a request handler. A `ResponseEvent` listener can change its answer, for
example to give stateless clients different instructions, and a handler added through `addRequestHandler()` replaces
it. See
[Customizing `server/discover`](protocol-eras.md#customizing-serverdiscover).

## Discovery Configuration

**Required when using MCP attributes.** If you're using PHP attributes (`#[McpTool]`, `#[McpResource]`, `#[McpResourceTemplate]`, `#[McpPrompt]`) to define your MCP elements, you **MUST** configure discovery to tell the server where to look for these attributes.

```php
use Mcp\Server;

$server = Server::builder()
    ->setDiscovery(
        basePath: __DIR__,
        scanDirs: ['.', 'src', 'lib'],           // Where to look for MCP attributes
        excludeDirs: ['vendor', 'tests'],        // Where NOT to look
        cache: $cacheInstance,                   // Optional: cache discovered elements
        namePatterns: ['*.php', '*.inc'],        // Optional: list of filename patterns to match
    );
```

Discovery needs the `symfony/finder` package. Without it, `build()` and `buildStateless()` throw a `RuntimeException`.
You don't need the package if you set your own discoverer with `setDiscoverer()`.
See [First server](../get-started/first-server.md) for the install command.

**Parameters:**

- `$basePath` (string): Base directory for discovery (typically `__DIR__`)
- `$scanDirs` (array): Directories to recursively scan for `#[McpTool]`, `#[McpResource]`, etc. All subdirectories are included. (default: `['.', 'src']`)
- `$excludeDirs` (array): Directory names to exclude **within** the scanned directories during recursive scanning
- `$cache` (CacheInterface|CacheItemPoolInterface|null): Optional PSR-16 cache or PSR-6 pool to store discovered elements for performance
- `$namePatterns` (array): Optional list of patterns (regexp, glob, or string) for file names (default: `['*.php']`)

**Basic Discovery (scans current directory and `src/`):**
```php
$server = Server::builder()
    ->setDiscovery(__DIR__, excludeDirs: ['vendor'])  // Scans '.' and 'src'
    ->build();
```

!!! warning
    Always exclude `vendor/` when the scanned directories contain it. The recursive scan
    tries to load every class it finds, and a single file that cannot be loaded makes the
    scan abort — the server then reports an **empty** element list instead of an error.

**Production Setup with Caching:**
```php
use Mcp\Server;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

// Cache discovered elements to avoid filesystem scanning on every server start
$cache = new FilesystemAdapter('mcp-discovery');

$server = Server::builder()
    ->setDiscovery(
        basePath: __DIR__,
        scanDirs: ['src', 'lib'],                    // Scan these directories recursively
        excludeDirs: ['vendor', 'tests', 'temp'],    // Skip these directory names within scanned dirs
        cache: $cache                                // Cache for performance
    )
    ->build();
```

**How `excludeDirs` works:**

- If scanning `src/` and there's `src/vendor/`, it will be excluded
- If scanning `lib/` and there's `lib/tests/`, it will be excluded
- But if `vendor/` and `tests/` are at the same level as `src/`, they're not scanned anyway (not in `scanDirs`)

> **Performance**: Always use a cache in production. The first run scans and caches all discovered MCP elements, making
> subsequent server startups nearly instantaneous.

## Service Dependencies

### Container

The container is used to resolve handlers and their dependencies when handlers inject dependencies in their constructors.
The SDK includes a basic container with simple auto-wiring capabilities.

```php
use Mcp\Capability\Registry\Container;

// Use the default basic container
$container = new Container();
$container->set(DatabaseService::class, new DatabaseService($pdo));
$container->set(\PDO::class, $pdo);

$server = Server::builder()
    ->setContainer($container)
    ->build();
```

**Basic Container Features:**

- Supports constructor auto-wiring for classes with parameterless constructors
- Resolves dependencies where all parameters are type-hinted classes/interfaces known to the container
- Supports parameters with default values
- Does NOT support scalar/built-in type injection without defaults
- Detects circular dependencies

You can also use any PSR-11 compatible container (Symfony DI, PHP-DI, Laravel Container, etc.).

### Logger

Provide a PSR-3 logger instance for internal server logging (request/response processing, errors, session management, transport events):

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('mcp.log', Logger::INFO));

$server = Server::builder()
    ->setLogger($logger);
```

### Event Dispatcher

Configure event dispatching:

```php
$server = Server::builder()
    ->setEventDispatcher($eventDispatcher);
```

### Fiber Scheduler

The server runs every request handler in a fiber, so that the handler can wait for the client through the
[`ClientGateway`](../handlers/client-communication.md). Under an event loop (e.g. Revolt, which AMPHP builds on,
or ReactPHP's async), the I/O of a handler suspends that same fiber. Set a fiber scheduler to tell the server how
to wait on your event loop:

```php
use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;
use Revolt\EventLoop;

final class RevoltFiberScheduler implements FiberSchedulerInterface
{
    public function wait(FiberSettlement $settlement): void
    {
        $suspension = EventLoop::getSuspension();
        $settlement->then($suspension->resume(...));
        $suspension->suspend();
    }
}

$server = Server::builder()
    ->setFiberScheduler(new RevoltFiberScheduler());
```

The server calls the callback you pass to `then()` while the handler fiber is still running, so the callback must
wake the waiting fiber on a later turn of the event loop. `Suspension::resume()` in Revolt already does this. With
ReactPHP, use `Loop::futureTick()`:

```php
use Mcp\Server\Fiber\FiberSchedulerInterface;
use Mcp\Server\Fiber\FiberSettlement;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;

final class ReactFiberScheduler implements FiberSchedulerInterface
{
    public function wait(FiberSettlement $settlement): void
    {
        $deferred = new Deferred();
        $settlement->then(static fn () => Loop::futureTick(static fn () => $deferred->resolve(null)));
        await($deferred->promise());
    }
}
```

If the callback wakes the fiber right away, the request fails with a `LogicException` that explains the fix.

Without a scheduler, a handler that awaits I/O fails the request with a `LogicException`. The event loop still
resumes the handler after that, so the handler runs to its end and its side effects still happen.

#### Limits Under an Event Loop

A fiber scheduler lets handlers await I/O while the event loop serves other requests. These limits apply:

- `StreamableHttpTransport` writes streamed responses to the PHP output, not to the PSR-7 response body. A server
  that sends the body itself (e.g. `amphp/http-server`) gets the SSE text of a `2026-07-28` response from
  `CallbackStream::chunks()`. On an empty chunk, an idle turn, it must wait on the loop before it asks for the next
  one, or the stream polls without pause; it may write a keep-alive then. Dropping the generator ends the stream,
  and the handler sends nothing more. Handshake-era responses have no chunks, so such a server can't deliver them,
  including any response that carries a notification, for example from `ClientGateway::notify()`. The Revolt and
  ReactPHP examples show both cases.
- When the client goes away, a handler stops at the next message it sends, not in the middle of an await.
- Without a subscription lifetime (`setSubscriptionLifetime(0)`), a `subscriptions/listen` stream stays open until
  the client closes it. Under an event loop, each open stream takes one of the server's connections, so its
  connection limits bound how many can be open.
- On protocol versions up to `2025-11-25`, the same transport blocks the event loop while a handler waits for an
  answer from the client (e.g. `ClientGateway::elicit()`). One process can't receive the answer during that time,
  so the request fails when the wait times out.
- `StdioTransport` reads the next message after the current handler returned or started to wait for the client.
  Requests over stdio run one after the other. Your own tasks on the event loop still run in between.
- Call the `ClientGateway` from the fiber of the handler. A call from another fiber (e.g. in `EventLoop::queue()`)
  never returns. If the handler waits for that fiber, the request gets no answer. If it doesn't wait, the request
  gets its answer and the message is lost without an error. A handler that passes a `NotificationSuspension` or
  `RequestSuspension` to `\Fiber::suspend()` itself after the event loop resumed it gets no answer either.
- A [session lock](sessions.md#concurrent-requests) stays held while a handler awaits I/O. Another request of the
  same session in the same process waits on the event loop until the lock is free. This wait has no timeout. If
  another process holds the lock, the lock backend blocks the event loop while it waits, up to its timeout.

### Fiber Context

Some libraries keep their context per fiber. For example, OpenTelemetry raises a warning when code reads its context
in a fiber it doesn't know. Set a fiber context to carry the context of the caller into the fiber of the handler:

```php
use Mcp\Server;
use Mcp\Server\Fiber\FiberContextInterface;
use OpenTelemetry\Context\Context;

final class OpenTelemetryFiberContext implements FiberContextInterface
{
    public function wrap(\Closure $handler): \Closure
    {
        // runs in the context of the caller
        $context = Context::getCurrent();

        // runs in the fiber of the handler
        return static function () use ($context, $handler): mixed {
            $scope = $context->activate();

            try {
                return $handler();
            } finally {
                $scope->detach();
            }
        };
    }
}

$server = Server::builder()
    ->setFiberContext(new OpenTelemetryFiberContext())
    ->build();
```

The returned closure must call `$handler` once and return its result. The `finally` block also runs when the server
discards a suspended fiber.

Under an event loop, every request runs in its own fiber, so OpenTelemetry raises the same warning in `wrap()`. Activate
a context in that fiber before you run the server, and detach it in a `finally` block:

```php
$scope = Context::getRoot()->activate();

try {
    $response = $server->run($transport);
} finally {
    $scope->detach();
}
```

Keep the returned scope in a variable. If PHP assertions are enabled, OpenTelemetry raises a notice when a scope is
destroyed before you detach it.

Fibers that a handler starts itself (e.g. with `Amp\async()`) don't get the context.

## Complete Example

Here's a comprehensive example showing all major configuration options:

```php
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Capability\Registry\Container;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Setup dependencies
$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('mcp.log', Logger::INFO));

$cache = new FilesystemAdapter('mcp-discovery');
$sessionStore = new FileSessionStore(__DIR__ . '/sessions');

// Setup container with dependencies
$container = new Container();
$container->set(\PDO::class, new \PDO('sqlite::memory:'));
$container->set(DatabaseService::class, new DatabaseService($container->get(\PDO::class)));

// Build server
$server = Server::builder()
    // Server identity
    ->setServerInfo('Advanced Calculator', '2.1.0')

    // Performance and behavior
    ->setPaginationLimit(100)
    ->setInstructions('Use calculate tool for math operations. Check config resource for current settings.')

    // Discovery with caching
    ->setDiscovery(__DIR__, ['src'], ['vendor', 'tests'], $cache)

    // Session management
    ->setSession($sessionStore)

    // Services
    ->setLogger($logger)
    ->setContainer($container)

    // Manual capability registration
    ->addTool([Calculator::class, 'advancedCalculation'], 'advanced_calc')
    ->addResource([Config::class, 'getSettings'], 'config://app/settings', 'app_settings')

    // Build the server
    ->build();
```

## Method Reference

| Method | Parameters | Description |
|--------|------------|-------------|
| `setServerInfo()` | name, version, description?, icons?, websiteUrl?, title? | Set server identity |
| `setPaginationLimit()` | paginationLimit | Set max items per page |
| `setInstructions()` | instructions | Set usage instructions |
| `setCapabilities()` | serverCapabilities | Set the server capabilities and turn off automatic detection |
| `enableExtension()` | ...extensions | Enable protocol extensions, see [Protocol extensions](../advanced/extensions.md) |
| `setProtocolVersion()` | protocolVersion | Pin the handshake to one protocol revision |
| `setModernVersions()` | versions | Narrow the revisions the modern (2026-07-28) leg answers for |
| `withoutModernEra()` | - | Serve the handshake era only |
| `setRequestState()` | key, ttl?, binder? | Signing key (or key ring, the first key signing), lifetime and optional binding to the originating request for multi round-trip request state |
| `setInputRequiredLimits()` | maxRounds, roundTimeout | Bound the input-required shim on handshake-era connections |
| `withoutInputRequiredShim()` | - | Do not fulfil an `InputRequiredResult` over a handshake-era connection |
| `setCachePolicy()` | policy | Set the `ttlMs`/`cacheScope` hints on cacheable results |
| `setNotificationBus()` | bus | Delivery for `subscriptions/listen` streams |
| `setSubscriptionLifetime()` | seconds | How long a subscription stream is held open over HTTP (`0` = unbounded) |
| `setResourceSubscriptionManager()` | subscriptionManager | Store `resources/subscribe` subscriptions somewhere other than the session |
| `setHeaderValidator()` | headerValidation? | Toggle the SEP-2243 standard-header check on the modern leg of `build()` and on `buildStateless()` |
| `setDiscovery()` | basePath, scanDirs?, excludeDirs?, cache?, namePatterns? | Configure attribute discovery |
| `setDiscoverer()` | discoverer | Replace the class that scans for attributes (the discovery `cache` is then not used) |
| `setSession()` | sessionStore?, sessionManager?, gcProbability?, gcDivisor? | Configure session management, see [Sessions](sessions.md) |
| `setSessionLock()` | sessionLock | Serialize the concurrent requests of one session, see [Sessions](sessions.md#concurrent-requests) |
| `setLogger()` | logger | Set PSR-3 logger |
| `setContainer()` | container | Set PSR-11 container |
| `setEventDispatcher()` | eventDispatcher | Set PSR-14 event dispatcher, see [Events](../advanced/events.md) |
| `setFiberScheduler()` | scheduler | Wait on an event loop (Revolt, ReactPHP) while a handler awaits I/O, see [Fiber Scheduler](#fiber-scheduler) |
| `setFiberContext()` | context | Carry execution context into handler fibers, see [Fiber Context](#fiber-context) |
| `setSchemaGenerator()` | schemaGenerator | Replace the generator that builds input schemas from PHP types, see [Schema generation](../servers/schemas.md) |
| `setSchemaValidator()` | schemaValidator | Replace the validator for tool arguments and structured output, see [Schema generation](../servers/schemas.md) |
| `setReferenceHandler()` | referenceHandler | Replace the class that calls the handlers of tools, resources and prompts |
| `setRegistry()` | registry | Use your own registry. It is loaded when the server is built |
| `setLazyLoading()` | lazyLoading? | Load elements on the first registry read (default) or, with `false`, when the server is built |
| `addLoader()` | loader | Add a custom loader that registers elements |
| `addLoaders()` | loaders | Add several custom loaders |
| `addRequestHandler()` | handler | Prepend a single custom request handler |
| `addRequestHandlers()` | handlers | Prepend multiple custom request handlers |
| `addNotificationHandler()` | handler | Prepend a single custom notification handler |
| `addNotificationHandlers()` | handlers | Prepend multiple custom notification handlers |
| `addTool()` | handler, name?, title?, description?, annotations?, inputSchema?, icons?, meta?, outputSchema? | Register tool |
| `addResource()` | handler, uri, name?, title?, description?, mimeType?, size?, annotations?, icons?, meta? | Register resource |
| `addResourceTemplate()` | handler, uriTemplate, name?, title?, description?, mimeType?, annotations?, meta? | Register resource template |
| `addPrompt()` | handler, name?, title?, description?, icons?, meta? | Register prompt |
| `add()` | definition, handler, completionProviders? | Register an element from a schema VO + handler pair, see [Explicit element registration](../servers/registration.md#explicit-element-registration) |
| `build()` | - | Create the server instance |
| `buildStateless()` | supportedVersions? | Create the modern dispatcher alone, for `StatelessHttpTransport` |
