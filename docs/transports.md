# Transports

Transports handle the communication layer between MCP servers and clients. The PHP MCP SDK provides two main transport implementations: STDIO for command-line integration and HTTP for web-based communication.

## Table of Contents

- [Transport Overview](#transport-overview)
- [STDIO Transport](#stdio-transport)
- [HTTP Transport](#http-transport)
- [Choosing a Transport](#choosing-a-transport)

## Transport Overview

All transports implement the `TransportInterface` and follow the same basic pattern:

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new SomeTransport();

$server->connect($transport);

$transport->listen(); // For STDIO, or handle response for HTTP
```

## STDIO Transport

The STDIO transport communicates via standard input/output streams, ideal for command-line tools and MCP client integrations.

```php
$transport = new StdioTransport(
    input: STDIN,           // Input stream (default: STDIN)
    output: STDOUT,         // Output stream (default: STDOUT)
    logger: $logger         // Optional PSR-3 logger
);
```

### Parameters

- **`input`** (optional): Input stream resource. Defaults to `STDIN`.
- **`output`** (optional): Output stream resource. Defaults to `STDOUT`.
- **`logger`** (optional): `LoggerInterface` - PSR-3 logger for debugging. Defaults to `NullLogger`.

> [!IMPORTANT]
> When using STDIO transport, **never** write to `STDOUT` in your handlers as it's reserved for JSON-RPC communication. Use `STDERR` for debugging instead.

### Example Server Script

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

$server = Server::builder()
    ->setServerInfo('STDIO Calculator', '1.0.0')
    ->addTool(function(int $a, int $b): int { return $a + $b; }, 'add_numbers')
    ->addTool(InvokableCalculator::class)
    ->build();

$transport = new StdioTransport();

$server->connect($transport);

$transport->listen();
```

### Client Configuration

For MCP clients like Claude Desktop:

```json
{
    "mcpServers": {
        "my-php-server": {
            "command": "php",
            "args": ["/absolute/path/to/server.php"]
        }
    }
}
```

## HTTP Transport

The HTTP transport was designed to sit between any PHP project, regardless of the HTTP implementation or how they receive and process requests and send responses. It provides a flexible architecture that can integrate with any PSR-7 compatible application.

```php
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

$transport = new StreamableHttpTransport(
    request: $serverRequest,           // PSR-7 server request
    responseFactory: $responseFactory, // PSR-17 response factory
    streamFactory: $streamFactory,     // PSR-17 stream factory
    logger: $logger                    // Optional PSR-3 logger
);
```

### Parameters

- **`request`** (required): `ServerRequestInterface` - The incoming PSR-7 HTTP request
- **`responseFactory`** (required): `ResponseFactoryInterface` - PSR-17 factory for creating HTTP responses
- **`streamFactory`** (required): `StreamFactoryInterface` - PSR-17 factory for creating response body streams
- **`logger`** (optional): `LoggerInterface` - PSR-3 logger for debugging. Defaults to `NullLogger`.

### Architecture

The HTTP transport doesn't run its own web server. Instead, it processes PSR-7 requests and returns PSR-7 responses that your application can handle however it needs to:

```
Your Web App → PSR-7 Request → StreamableHttpTransport → PSR-7 Response → Your Web App
```

This design allows integration with any PHP framework or application that supports PSR-7.

### Basic Usage (Standalone)

Here's an opinionated example using Nyholm PSR-7 and Laminas emitter:

```php
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Session\FileSessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

// Create PSR-7 request from globals
$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

// Build server
$server = Server::builder()
    ->setServerInfo('HTTP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
    ->setSession(new FileSessionStore(__DIR__ . '/sessions')) // HTTP needs persistent sessions
    ->build();

// Process request and get response
$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);
$server->connect($transport);
$response = $transport->listen();

// Emit response
(new SapiEmitter())->emit($response);
```

### Framework Integration

#### Symfony Integration

First install the required PSR libraries:

```bash
composer require symfony/psr-http-message-bridge nyholm/psr7
```

Then create a controller that uses Symfony's PSR-7 bridge:

> **Note**: This example assumes your MCP `Server` instance is configured in Symfony's service container.

```php
// In a Symfony controller
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

class McpController
{
    #[Route('/mcp', name: 'mcp_endpoint']
    public function handle(Request $request, Server $mcpServer): Response
    {
        // Create PSR-7 factories
        $psr17Factory = new Psr17Factory();
        $psrHttpFactory = new PsrHttpFactory($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $httpFoundationFactory = new HttpFoundationFactory();
        
        // Convert Symfony request to PSR-7
        $psrRequest = $psrHttpFactory->createRequest($request);
        
        // Process with MCP
        $transport = new StreamableHttpTransport($psrRequest, $psr17Factory, $psr17Factory);
        $mcpServer->connect($transport);
        $psrResponse = $transport->listen();
        
        // Convert PSR-7 response back to Symfony
        return $httpFoundationFactory->createResponse($psrResponse);
    }
}
```

#### Laravel Integration

First install the required PSR libraries:

```bash
composer require symfony/psr-http-message-bridge nyholm/psr7
```

Then create a controller that type-hints `ServerRequestInterface`:

> **Note**: This example assumes your MCP `Server` instance is constructed and bound in a Laravel service provider for dependency injection.

```php
// In a Laravel controller
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;

class McpController
{
    public function handle(ServerRequestInterface $request, Server $mcpServer): ResponseInterface
    {
        $psr17Factory = new Psr17Factory();
        
        // Create and connect the MCP HTTP transport
        $transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);
        $mcpServer->connect($transport);
        
        // Process MCP request and return PSR-7 response
        // Laravel automatically handles PSR-7 responses
        return $transport->listen();
    }
}

// Route registration
Route::any('/mcp', [McpController::class, 'handle']);
```

#### Slim Framework Integration

Slim Framework works natively with PSR-7.

Create a route handler using Slim's built-in factories and container:

```php
use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

$app = AppFactory::create();
$container = $app->getContainer();

$container->set('mcpServer', function (ContainerInterface $container) {
    return Server::builder()
        ->setServerInfo('My MCP Server', '1.0.0')
        ->setDiscovery(__DIR__, ['.'])
        ->build();
});

$app->any('/mcp', function ($request, $response) {
    $mcpServer = $this->get('mcpServer');
    
    $responseFactory = new ResponseFactory();
    $streamFactory = new StreamFactory();
    
    $transport = new StreamableHttpTransport($request, $responseFactory, $streamFactory);
    $mcpServer->connect($transport);
    
    return $transport->listen();
});
```

### HTTP Method Handling

The transport handles all HTTP methods automatically:

- **POST**: Send MCP requests
- **GET**: Not implemented (returns 405)
- **DELETE**: End session
- **OPTIONS**: CORS preflight

You should route **all methods** to your MCP endpoint, not just POST.

### Session Management

HTTP transport requires persistent sessions since PHP doesn't maintain state between requests. Unlike STDIO transport where in-memory sessions work fine, HTTP transport needs a persistent session store:

```php
use Mcp\Server\Session\FileSessionStore;

// ✅ Good for HTTP
$server = Server::builder()
    ->setSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->build();

// ❌ Not recommended for HTTP (sessions lost between requests)
$server = Server::builder()
    ->setSession(new InMemorySessionStore())
    ->build();
```

### Recommended Route

It's recommended to mount the MCP endpoint at `/mcp`, but this is not enforced:

```php
// Recommended
Route::any('/mcp', [McpController::class, 'handle']);

// Also valid
Route::any('/', [McpController::class, 'handle']);
Route::any('/api/mcp', [McpController::class, 'handle']);
```

### Testing HTTP Transport

Use the MCP Inspector to test HTTP servers:

```bash
# Start your PHP server
php -S localhost:8000 server.php

# Connect with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000
```

## Choosing a Transport

### Use STDIO When:

- Building command-line tools
- Integrating with MCP clients (Claude Desktop, etc.)
- Simple request/response patterns
- Process-based isolation is desired
- Single session per process

### Use HTTP When:

- Building web services
- Need browser compatibility
- Integration with web frameworks
- Multiple concurrent sessions
- Web-based MCP clients
- RESTful API patterns

The key difference is that STDIO is process-based (one session per process) while HTTP is request-based (multiple sessions via headers).