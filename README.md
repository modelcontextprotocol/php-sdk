# MCP PHP SDK

The official PHP SDK for Model Context Protocol (MCP). It provides a framework-agnostic API for implementing MCP servers
and clients in PHP.

> [!IMPORTANT]
> This SDK is currently in active development with ongoing refinement of its architecture and features. While
> functional, the API may experience changes as we work toward stabilization.
> 
> If you want to help us stabilize the SDK, please see the [issue tracker](https://github.com/modelcontextprotocol/php-sdk/issues).

This project represents a collaboration between [the PHP Foundation](https://thephp.foundation/) and the [Symfony project](https://symfony.com/). It adopts
development practices and standards from the Symfony project, including [Coding Standards](https://symfony.com/doc/current/contributing/code/standards.html) and the
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Until the first major release, this SDK is considered [experimental](https://symfony.com/doc/current/contributing/code/experimental.html).

## Roadmap

**Features**
- [ ] Stabilize server component with all needed handlers and functional tests
- [ ] Extend documentation, including integration guides for popular frameworks
- [ ] Implement Client component
- [ ] Support multiple schema versions

## Installation

```bash
composer require mcp/sdk
```

## Quick Start

This example demonstrates the most common usage pattern - a STDIO server using attribute discovery.

### 1. Define Your MCP Elements

Create a class with MCP capabilities using attributes:

```php
<?php

namespace App;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\McpResource;

class CalculatorElements
{
    /**
     * Adds two numbers together.
     * 
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    /**
     * Performs basic arithmetic operations.
     */
    #[McpTool(name: 'calculate')]
    public function calculate(float $a, float $b, string $operation): float|string
    {
        return match($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b != 0 ? $a / $b : 'Error: Division by zero',
            default => 'Error: Unknown operation'
        };
    }

    #[McpResource(
        uri: 'config://calculator/settings',
        name: 'calculator_config',
        mimeType: 'application/json'
    )]
    public function getSettings(): array
    {
        return ['precision' => 2, 'allow_negative' => true];
    }
}
```

### 2. Create the Server Script

Create your MCP server:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

$server = Server::builder()
    ->setServerInfo('Calculator Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport();

$server->run($transport);
```

### 3. Configure Your MCP Client

Add to your client configuration (e.g., Claude Desktop's `mcp.json`):

```json
{
    "mcpServers": {
        "php-calculator": {
            "command": "php",
            "args": ["/absolute/path/to/your/server.php"]
        }
    }
}
```

### 4. Test Your Server

```bash
# Test with MCP Inspector
npx @modelcontextprotocol/inspector php /path/to/server.php

# Your AI assistant can now call:
# - add: Add two integers
# - calculate: Perform arithmetic operations
# - Read config://calculator/settings resource
```

## Key Features

### Attribute-Based Discovery

Define MCP elements using PHP attributes with automatic discovery:

```php
// Tool with automatic name and description from method
#[McpTool]
public function generateReport(): string { /* ... */ }

// Tool with custom name
#[McpTool(name: 'custom_name')]
public function myMethod(): string { /* ... */ }

// Resource with URI and metadata
#[McpResource(uri: 'config://app/settings', mimeType: 'application/json')]
public function getConfig(): array { /* ... */ }
```

### Manual Registration

Register capabilities programmatically:

```php
$server = Server::builder()
    ->addTool([MyClass::class, 'myMethod'], 'tool_name')
    ->addResource([MyClass::class, 'getData'], 'data://config')
    ->build();
```

### Multiple Transport Options

**STDIO Transport** (Command-line integration):
```php
$transport = new StdioTransport();
$server->run($transport);
```

**HTTP Transport** (Web-based communication):
```php
$transport = new StreamableHttpTransport($request, $responseFactory, $streamFactory);
$response = $server->run($transport);
// Handle $response in your web application
```

### Session Management

By default, the SDK uses in-memory sessions. You can configure different session stores:

```php
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Psr16StoreSession;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Cache\Adapter\RedisAdapter;

// Use default in-memory sessions with custom TTL
$server = Server::builder()
    ->setSession(ttl: 7200) // 2 hours
    ->build();

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
    ->setSession(new Psr16StoreSession(
        cache: new Psr16Cache($redisAdapter),
        prefix: 'mcp-',
        ttl: 3600
    ))
    ->build();
```

### Discovery Caching

Use any PSR-16 cache implementation to cache discovery results and avoid running discovery on every server start:

```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new FilesystemAdapter('mcp-discovery'));

$server = Server::builder()
    ->setDiscovery(
        basePath: __DIR__,
        scanDirs: ['.', 'src'],      // Default: ['.', 'src']
        excludeDirs: ['vendor'],     // Default: ['vendor', 'node_modules']
        cache: $cache
    )
    ->build();
```

## Documentation

**Core Concepts:**
- [Server Builder](docs/server-builder.md) - Complete ServerBuilder reference and configuration
- [Transports](docs/transports.md) - STDIO and HTTP transport setup and usage
- [MCP Elements](docs/mcp-elements.md) - Creating tools, resources, and prompts
- [Client Communication](docs/client-communication.md) - Communicating back to the client from server-side
- [Events](docs/events.md) - Hooking into server lifecycle with events

**Learning:**
- [Examples](docs/examples.md) - Comprehensive example walkthroughs

**External Resources:**
- [Model Context Protocol documentation](https://modelcontextprotocol.io)
- [Model Context Protocol specification](https://spec.modelcontextprotocol.io)
- [Officially supported servers](https://github.com/modelcontextprotocol/servers)

## PHP Libraries Using the MCP SDK

* [pronskiy/mcp](https://github.com/pronskiy/mcp) - Additional DX layer
* [symfony/mcp-bundle](https://github.com/symfony/mcp-bundle) - Symfony integration bundle
* [josbeir/cakephp-synapse](https://github.com/josbeir/cakephp-synapse) - CakePHP integration plugin

## Contributing

We are passionate about supporting contributors of all levels of experience and would love to see you get involved in
the project. See the [contributing guide](CONTRIBUTING.md) to get started before you [report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and [send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls).

## Credits

The starting point for this SDK was the [PHP-MCP](https://github.com/php-mcp/server) project, initiated by
[Kyrian Obikwelu](https://github.com/CodeWithKyrian), and the [Symfony AI initiative](https://github.com/symfony/ai). We are grateful for the work
done by both projects and their contributors, which created a solid foundation for this SDK.

## License

This project is licensed under the Apache License, Version 2.0 for new contributions, with existing code under the MIT License - see the [LICENSE](LICENSE) file for details.
