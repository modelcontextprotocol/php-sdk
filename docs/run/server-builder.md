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
    ->setDiscovery(__DIR__, ['.'])
    ->build();
```

### Method 2: Direct Instantiation

```php
use Mcp\Server\Builder;

$server = (new Builder())
    ->setServerInfo('My MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
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
not need to configure anything. See [Protocol Version Negotiation](#protocol-version-negotiation) below for how that
negotiation resolves, and for what `setProtocolVersion()` changes:

```php
use Mcp\Schema\Enum\ProtocolVersion;

$server = Server::builder()
    ->setProtocolVersion(ProtocolVersion::V2025_06_18);
```

## Protocol Version Negotiation

MCP revisions are identified by a date string such as `2025-11-25`. The client names the revision it wants to speak in
its `initialize` request, and the server answers with the revision the connection will actually use. Both sides
disconnect if they cannot agree. This follows the
[protocol version negotiation](https://modelcontextprotocol.io/specification/draft/basic/versioning#protocol-version-negotiation)
section of the specification.

The SDK's known revisions live in `Mcp\Schema\Enum\ProtocolVersion`, declared oldest to newest:

```php
use Mcp\Schema\Enum\ProtocolVersion;

ProtocolVersion::latestHandshake();   // newest revision reachable via `initialize`
ProtocolVersion::handshakeVersions(); // every revision the server will negotiate, oldest first
ProtocolVersion::V2025_11_25->isAtLeast(ProtocolVersion::V2025_06_18); // true
```

Comparisons go through declaration order rather than string collation. The identifiers happen to be ISO dates today,
but they are an enumerated set rather than an ordered scalar, so nothing should assume they sort chronologically.

### How the server answers

| Client requests | Server responds with |
| --- | --- |
| A revision the server supports | That same revision |
| An unknown or malformed revision | `ProtocolVersion::latestHandshake()` as a counter-offer |
| A modern revision such as `2026-07-28` | `ProtocolVersion::latestHandshake()` as a counter-offer |

A counter-offer is not an error: the client decides whether it can continue on the offered revision or must close the
connection. The negotiated revision is stored on the session under `protocol_version`.

The last row is not a rejection of an unknown revision — the SDK knows `2026-07-28`, it just cannot be reached through
this handshake. The modern era replaced `initialize` with per-request metadata, so answering with one of its revisions
would leave a connection neither side could use. Serving that era is separate work; today the server only knows not to
mis-negotiate it.

This table is mirrored by the `provideNegotiationTable()` data provider in
`tests/Unit/Server/Handler/Request/InitializeHandlerTest.php`, which drives its supported-revision rows off the enum so
a newly declared revision is covered automatically.

### Pinning a revision

`setProtocolVersion()` pins the handshake to exactly one revision instead of negotiating across the supported set. The
pin wins over the client's request, so a client asking for anything else receives the pinned revision as a
counter-offer and has to decide whether to continue. Leave it unset unless you have a reason to refuse other revisions.

!!! note
    On the Streamable HTTP transport, every request after the handshake also carries an `MCP-Protocol-Version` header,
    which is validated separately by `ProtocolVersionMiddleware`. The pin does not reach that check: the transport
    builds the middleware without access to the server configuration, so the header keeps being accepted for every
    revision in `ProtocolVersion::handshakeVersions()`. To narrow it too, construct the middleware yourself with the
    same revision — see [Protocol Version Validation](http.md#protocol-version-validation).

## Discovery Configuration

**Required when using MCP attributes.** If you're using PHP attributes (`#[McpTool]`, `#[McpResource]`, `#[McpResourceTemplate]`, `#[McpPrompt]`) to define your MCP elements, you **MUST** configure discovery to tell the server where to look for these attributes.

```php
$server = Server::builder()
    ->setDiscovery(
        basePath: __DIR__,
        scanDirs: ['.', 'src', 'lib'],           // Where to look for MCP attributes
        excludeDirs: ['vendor', 'tests'],        // Where NOT to look
        cache: $cacheInstance,                   // Optional: cache discovered elements
        namePatterns: ['*.php', '*.inc'],        // Optional: list of filename patterns to match
    );
```

**Parameters:**
- `$basePath` (string): Base directory for discovery (typically `__DIR__`)
- `$scanDirs` (array): Directories to recursively scan for `#[McpTool]`, `#[McpResource]`, etc. All subdirectories are included. (default: `['.', 'src']`)
- `$excludeDirs` (array): Directory names to exclude **within** the scanned directories during recursive scanning
- `$cache` (CacheInterface|null): Optional PSR-16 cache to store discovered elements for performance
- `$namePatterns` (array): Optional list of patterns (regexp, glob, or string) for file names (default: `['*.php']`)

**Basic Discovery (scans current directory and `src/`):**
```php
$server = Server::builder()
    ->setDiscovery(__DIR__)  // Minimal setup
    ->build();
```

**Production Setup with Caching:**
```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

// Cache discovered elements to avoid filesystem scanning on every server start
$cache = new Psr16Cache(new FilesystemAdapter('mcp-discovery'));

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

## Complete Example

Here's a comprehensive example showing all major configuration options:

```php
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Capability\Registry\Container;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Setup dependencies
$logger = new Logger('mcp-server');
$logger->pushHandler(new StreamHandler('mcp.log', Logger::INFO));

$cache = new Psr16Cache(new FilesystemAdapter('mcp-discovery'));
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
| `setServerInfo()` | name, version, description? | Set server identity |
| `setPaginationLimit()` | limit | Set max items per page |
| `setInstructions()` | instructions | Set usage instructions |
| `setProtocolVersion()` | protocolVersion | Pin the handshake to one protocol revision |
| `setDiscovery()` | basePath, scanDirs?, excludeDirs?, cache? | Configure attribute discovery |
| `setSession()` | sessionStore?, sessionManager?, gcProbability?, gcDivisor? | Configure session management |
| `setLogger()` | logger | Set PSR-3 logger |
| `setContainer()` | container | Set PSR-11 container |
| `setEventDispatcher()` | dispatcher | Set PSR-14 event dispatcher |
| `addRequestHandler()` | handler | Prepend a single custom request handler |
| `addRequestHandlers()` | handlers | Prepend multiple custom request handlers |
| `addNotificationHandler()` | handler | Prepend a single custom notification handler |
| `addNotificationHandlers()` | handlers | Prepend multiple custom notification handlers |
| `addTool()` | handler, name?, title?, description?, annotations?, inputSchema?, ... | Register tool |
| `addResource()` | handler, uri, name?, title?, description?, mimeType?, size?, annotations?, icons?, meta? | Register resource |
| `addResourceTemplate()` | handler, uriTemplate, name?, title?, description?, mimeType?, annotations?, meta? | Register resource template |
| `addPrompt()` | handler, name?, title?, description?, icons?, meta? | Register prompt |
| `add()` | definition, handler | Register an element from a schema VO + handler pair |
| `build()` | - | Create the server instance |
