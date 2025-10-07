# Server Builder

The server `Builder` is a fluent builder class that simplifies the creation and configuration of an MCP server instance.
It provides methods for setting server information, configuring discovery, registering capabilities, and customizing
various aspects of the server behavior.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Server Configuration](#server-configuration)
- [Discovery Configuration](#discovery-configuration)
- [Session Management](#session-management)
- [Manual Capability Registration](#manual-capability-registration)
- [Service Dependencies](#service-dependencies)
- [Custom Method Handlers](#custom-method-handlers)
- [Complete Example](#complete-example)
- [Method Reference](#method-reference)

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
$server = Server::builder()
    ->setServerInfo('Calculator Server', '1.2.0', 'Advanced mathematical calculations');
```

**Parameters:**
- `$name` (string): The server name
- `$version` (string): Version string (semantic versioning recommended)
- `$description` (string|null): Optional description

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

## Discovery Configuration

**Required when using MCP attributes.** If you're using PHP attributes (`#[McpTool]`, `#[McpResource]`, `#[McpResourceTemplate]`, `#[McpPrompt]`) to define your MCP elements, you **MUST** configure discovery to tell the server where to look for these attributes.

```php
$server = Server::builder()
    ->setDiscovery(
        basePath: __DIR__,
        scanDirs: ['.', 'src', 'lib'],           // Where to look for MCP attributes
        excludeDirs: ['vendor', 'tests'],        // Where NOT to look 
        cache: $cacheInstance                    // Optional: cache discovered elements
    );
```

**Parameters:**
- `$basePath` (string): Base directory for discovery (typically `__DIR__`)
- `$scanDirs` (array): Directories to recursively scan for `#[McpTool]`, `#[McpResource]`, etc. All subdirectories are included. (default: `['.', 'src']`)
- `$excludeDirs` (array): Directory names to exclude **within** the scanned directories during recursive scanning
- `$cache` (CacheInterface|null): Optional PSR-16 cache to store discovered elements for performance

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

## Session Management

Configure session storage and lifecycle. By default, the SDK uses `InMemorySessionStore`:

```php
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;

// Use default in-memory sessions with custom TTL
$server = Server::builder()
    ->setSession(ttl: 7200) // 2 hours
    ->build();

// Override with file-based storage
$server = Server::builder()
    ->setSession(new FileSessionStore('/tmp/mcp-sessions'))
    ->build();

// Override with in-memory storage and custom TTL
$server = Server::builder()
    ->setSession(new InMemorySessionStore(3600))
    ->build();
```

**Available Session Stores:**
- `InMemorySessionStore`: Fast in-memory storage (default)
- `FileSessionStore`: Persistent file-based storage

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

## Manual Capability Registration

Register MCP elements programmatically without using attributes. The handler is the most important parameter and can be any PHP callable.

### Handler Types

**Handler** can be any PHP callable:

1. **Closure**: `function(int $a, int $b): int { return $a + $b; }`
2. **Class and method name pair**: `[ClassName::class, 'methodName']` - class must be constructable through the container
3. **Class instance and method name**: `[$instance, 'methodName']`
4. **Invokable class name**: `InvokableClass::class` - class must be constructable through the container and have `__invoke` method

### Manual Tool Registration

```php
$server = Server::builder()
    // Using closure
    ->addTool(
        handler: function(int $a, int $b): int { return $a + $b; },
        name: 'add_numbers',
        description: 'Adds two numbers together'
    )
    
    // Using class method pair
    ->addTool(
        handler: [Calculator::class, 'multiply'],
        name: 'multiply_numbers'
        // name and description are optional - derived from method name and docblock
    )
    
    // Using instance method
    ->addTool(
        handler: [$calculatorInstance, 'divide']
    )
    
    // Using invokable class
    ->addTool(
        handler: InvokableCalculator::class
    );
```

### Manual Resource Registration

Register static resources:

```php
$server = Server::builder()
    ->addResource(
        handler: [Config::class, 'getSettings'],
        uri: 'config://app/settings',
        name: 'app_config',
        description: 'Application configuration',
        mimeType: 'application/json'
    );
```

### Manual Resource Template Registration

Register dynamic resources with URI templates:

```php
$server = Server::builder()
    ->addResourceTemplate(
        handler: [UserService::class, 'getUserProfile'],
        uriTemplate: 'user://{userId}/profile',
        name: 'user_profile',
        description: 'User profile by ID',
        mimeType: 'application/json'
    );
```

### Manual Prompt Registration

Register prompt generators:

```php
$server = Server::builder()
    ->addPrompt(
        handler: [PromptService::class, 'generatePrompt'],
        name: 'custom_prompt',
        description: 'A custom prompt generator'
    );
```

**Note:** `name` and `description` are optional for all manual registrations. If not provided, they will be derived from
the handler's method name and docblock.

For more details on MCP elements, handlers, and attribute-based discovery, see [MCP Elements](mcp-elements.md).

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

## Custom Method Handlers

**Low-level escape hatch.** Custom method handlers run before the SDK’s built-in handlers and give you total control over
individual JSON-RPC methods. They do not receive the builder’s registry, container, or discovery output unless you pass
those dependencies in yourself.

Attach handlers with `addMethodHandler()` (single) or `addMethodHandlers()` (multiple). You can call these methods as
many times as needed; each call prepends the handlers so they execute before the defaults:

```php
$server = Server::builder()
    ->addMethodHandler(new AuditHandler())
    ->addMethodHandlers([
        new CustomListToolsHandler(),
        new CustomCallToolHandler(),
    ])
    ->build();
```

Custom handlers implement `MethodHandlerInterface`:

```php
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Server\Handler\MethodHandlerInterface;
use Mcp\Server\Session\SessionInterface;

interface MethodHandlerInterface
{
    public function supports(HasMethodInterface $message): bool;

    public function handle(HasMethodInterface $message, SessionInterface $session);
}
```

- `supports()` decides if the handler should look at the incoming message.
- `handle()` must return a JSON-RPC `Response`, an `Error`, or `null`.

Check out `examples/custom-method-handlers/server.php` for a complete example showing how to implement 
custom `tool/list` and `tool/call` methods independently of the registry.

> **Warning**: Custom method handlers bypass discovery, manual capability registration, and container lookups (unlesss 
> you explicitly pass them). Tools, resources, and prompts you register elsewhere will not show up unless your handler
> loads and executes them manually.
> Reach for this API only when you need that level of control and are comfortable taking on the additional plumbing.

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
| `setDiscovery()` | basePath, scanDirs?, excludeDirs?, cache? | Configure attribute discovery |
| `setSession()` | store?, factory?, ttl? | Configure session management |
| `setLogger()` | logger | Set PSR-3 logger |
| `setContainer()` | container | Set PSR-11 container |
| `setEventDispatcher()` | dispatcher | Set PSR-14 event dispatcher |
| `addMethodHandler()` | handler | Prepend a single custom method handler |
| `addMethodHandlers()` | handlers | Prepend multiple custom method handlers |
| `addTool()` | handler, name?, description?, annotations?, inputSchema? | Register tool |
| `addResource()` | handler, uri, name?, description?, mimeType?, size?, annotations? | Register resource |
| `addResourceTemplate()` | handler, uriTemplate, name?, description?, mimeType?, annotations? | Register resource template |
| `addPrompt()` | handler, name?, description? | Register prompt |
| `build()` | - | Create the server instance |
