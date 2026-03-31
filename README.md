# MCP PHP SDK

The official PHP SDK for Model Context Protocol (MCP). It provides a framework-agnostic API for implementing MCP servers
and clients in PHP.

This project represents a collaboration between [the PHP Foundation](https://thephp.foundation/) and the [Symfony project](https://symfony.com/). It adopts
development practices and standards from the Symfony project, including [Coding Standards](https://symfony.com/doc/current/contributing/code/standards.html) and the
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Until the first major release, this SDK is considered [experimental](https://symfony.com/doc/current/contributing/code/experimental.html), please see the [roadmap](./ROADMAP.md) for
planned next steps and features.

## Table of Contents

- [Installation](#installation)
- [Overview](#overview)
- [Server SDK](#server-sdk)
- [Client SDK](#client-sdk)
- [Documentation](#documentation)
- [External Resources](#external-resources)
- [PHP Libraries Using the MCP SDK](#php-libraries-using-the-mcp-sdk)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Installation

```bash
composer require mcp/sdk
```

## Overview

The MCP PHP SDK provides both **server** and **client** implementations for the Model Context Protocol, enabling you to:

- **Build MCP Servers**: Expose your PHP application's functionality (tools, resources, prompts) to AI agents
- **Build MCP Clients**: Connect to and interact with MCP servers from your PHP applications

## Server SDK

Build MCP servers to expose your PHP application's capabilities to AI agents like Claude, Codex, and others.

### Quick Start

```php
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\McpResource;

// Define capabilities using PHP attributes
class CalculatorCapabilities
{
    #[McpTool]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[McpResource(uri: 'config://calculator/settings')]
    public function getSettings(): array
    {
        return ['precision' => 2];
    }
}

// Build and run the server
$server = Server::builder()
    ->setServerInfo('Calculator Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])  // Auto-discover attributes
    ->build();

$transport = new StdioTransport();
$server->run($transport);
```

### Server Capabilities

- **Tools**: Executable functions that AI agents can call
- **Resources**: Data sources that can be read (files, configs, databases)
- **Resource Templates**: Dynamic resources with URI parameters
- **Prompts**: Pre-defined templates for AI interactions
- **Server-Initiated Communication**: Elicitations, sampling, logging, progress notifications

### Registration Methods

There are multiple ways to register your MCP capabilities—choose the approach that best fits your application's architecture:

**1. Attribute-Based Discovery** — Define capabilities using PHP attributes for automatic discovery:
```php
#[McpTool]
public function generateReport(): string { /* ... */ }

#[McpResource(uri: 'config://app/settings')]
public function getConfig(): array { /* ... */ }
```

**2. Manual Registration** — Register capabilities programmatically without attributes:
```php
$server = Server::builder()
    ->addTool([Calculator::class, 'add'], 'add_numbers')
    ->addResource([Config::class, 'get'], 'config://app')
    ->build();
```

**3. Hybrid Approach** — Combine both methods for maximum flexibility:
```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])
    ->addTool([ExternalService::class, 'process'], 'external')
    ->build();
```

### Transports

Choose the transport that matches your deployment environment:

**1. STDIO Transport** — For command-line integration and local processes:
```php
$transport = new StdioTransport();
$server->run($transport);
```

**2. HTTP Transport** — For web-based servers and distributed systems:
```php
$transport = new StreamableHttpTransport($request, $responseFactory, $streamFactory);
$response = $server->run($transport);
```

### Session Management

Configure session storage to maintain state between requests. Choose the backend that fits your infrastructure:

**In-Memory** (default, suitable for STDIO):
```php
$server = Server::builder()
    ->setSession(ttl: 7200) // 2 hours
    ->build();
```

**File-Based** (suitable for single-server HTTP deployments):
```php
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->build();
```

**PSR-16 Cache** (for example with Redis for scaled deployments):
```php
$server = Server::builder()
    ->setSession(new Psr16SessionStore(
        cache: new Psr16Cache($redisAdapter),
        prefix: 'mcp-',
        ttl: 3600
    ))
    ->build();
```

[→ Server Documentation](docs/server-builder.md)

## Client SDK

Connect to MCP servers from your PHP applications to access their tools, resources, and prompts.

### Quick Start

```php
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

// Build the client
$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->build();

// Connect to a server
$transport = new StdioTransport(
    command: 'php',
    args: ['/path/to/server.php'],
);

$client->connect($transport);

// Discover and use capabilities
$tools = $client->listTools();
$result = $client->callTool('add', ['a' => 5, 'b' => 3]);

$resources = $client->listResources();
$content = $client->readResource('config://calculator/settings');

$client->disconnect();
```

### Client Capabilities

- **Tool Calling**: List and execute tools from any MCP server
- **Resource Access**: Read static and dynamic resources
- **Prompt Management**: List and retrieve prompt templates
- **Completion Support**: Request argument completion suggestions

### Advanced Features

- **Progress Tracking**: Real-time progress during long operations
```php
$result = $client->callTool(
    name: 'process_data',
    arguments: ['dataset' => 'large_file.csv'],
    onProgress: function (float $progress, ?float $total, ?string $message) {
        echo "Progress: {$progress}/{$total} - {$message}\n";
    }
);
```

- **Sampling Support**: Handle server LLM sampling requests
```php
$samplingHandler = new SamplingRequestHandler($myCallback);
$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(sampling: true))
    ->addRequestHandler($samplingHandler)
    ->build();
```

- **Logging Notifications**: Receive server log messages
```php
$loggingHandler = new LoggingNotificationHandler($myCallback);
$client = Client::builder()
    ->addNotificationHandler($loggingHandler)
    ->build();
```

### Transports

Connect to MCP servers using the transport that matches your setup:

**1. STDIO Transport** — Connect to local server processes:
```php
$transport = new StdioTransport(
    command: 'php',
    args: ['/path/to/server.php'],
);

$client->connect($transport);
```

**2. HTTP Transport** — Connect to remote or web-based servers:
```php
$transport = new HttpTransport('http://localhost:8000');

$client->connect($transport);
```

[→ Client Documentation](docs/client.md)

## Documentation

### Core Concepts

- **[Server Builder](docs/server-builder.md)** — Complete ServerBuilder reference and configuration
- **[Client](docs/client.md)** — Client SDK for connecting to and communicating with MCP servers
- **[Transports](docs/transports.md)** — STDIO and HTTP transport setup and usage
- **[MCP Elements](docs/mcp-elements.md)** — Creating tools, resources, prompts, and templates
- **[Server-Client Communication](docs/server-client-communication.md)** — Sampling, logging, progress, and notifications
- **[Events](docs/events.md)** — Hooking into server lifecycle with events

### Learning & Examples

- **[Examples](docs/examples.md)** — Comprehensive example walkthroughs for servers and clients
- **[ROADMAP.md](ROADMAP.md)** — Planned features and development roadmap

## External Resources

- **[Model Context Protocol Documentation](https://modelcontextprotocol.io)** — Official MCP documentation
- **[Model Context Protocol Specification](https://spec.modelcontextprotocol.io)** — Protocol specification
- **[Officially Supported Servers](https://github.com/modelcontextprotocol/servers)** — Reference server implementations

## PHP Libraries Using the MCP SDK

- [pronskiy/mcp](https://github.com/pronskiy/mcp) — Additional developer experience layer
- [symfony/mcp-bundle](https://github.com/symfony/mcp-bundle) — Symfony integration bundle
- [josbeir/cakephp-synapse](https://github.com/josbeir/cakephp-synapse) — CakePHP integration plugin

## Contributing

We are passionate about supporting contributors of all levels of experience and would love to see you get involved in the project.

See the [Contributing Guide](CONTRIBUTING.md) to get started before you [report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and [send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls).

## Credits

The starting point for this SDK was the [PHP-MCP](https://github.com/php-mcp/server) project, initiated by [Kyrian Obikwelu](https://github.com/CodeWithKyrian), and the [Symfony AI initiative](https://github.com/symfony/ai). We are grateful for the work done by both projects and their contributors, which created a solid foundation for this SDK.

## License

This project is licensed under the Apache License, Version 2.0 for new contributions, with existing code under the MIT License — see the [LICENSE](LICENSE) file for details.
