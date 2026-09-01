# Framework integration

The HTTP transport is a PSR-7 request handler, not a web server. This page
covers how it fits into an application you already have.

## Architecture

The HTTP transport doesn't run its own web server. Instead, it processes PSR-7 requests and returns PSR-7 responses that
your application can handle however it needs to:

```
Your Web App → PSR-7 Request → StreamableHttpTransport → PSR-7 Response → Your Web App
```

This design allows integration with any PHP framework or application that supports PSR-7.

## Basic Usage (Standalone)

Here's a simplified example using PSR-17 discovery and Laminas emitter:

```php
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$server = Server::builder()
    ->setServerInfo('HTTP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->setSession(new FileSessionStore(__DIR__ . '/sessions')) // HTTP needs persistent sessions
    ->build();

$transport = new StreamableHttpTransport($request);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
```

## Framework Integration

### Symfony Integration

First install the required PSR libraries:

```bash
composer require symfony/psr-http-message-bridge nyholm/psr7
```

Then create a controller that uses Symfony's PSR-7 bridge:

> **Note**: This example assumes your MCP `Server` instance is configured in Symfony's service container.

```php
// In a Symfony controller
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class McpController
{
    #[Route('/mcp', name: 'mcp_endpoint')]
    public function handle(Request $request, Server $server): Response
    {
        // Convert Symfony request to PSR-7 (PSR-17 factories auto-discovered)
        $psrHttpFactory = new PsrHttpFactory();
        $httpFoundationFactory = new HttpFoundationFactory();
        $psrRequest = $psrHttpFactory->createRequest($request);

        // Process with MCP (factories auto-discovered)
        $transport = new StreamableHttpTransport($psrRequest);
        $psrResponse = $server->run($transport);

        // Convert PSR-7 response back to Symfony
        return $httpFoundationFactory->createResponse($psrResponse);
    }
}
```

### Laravel Integration

First install the required PSR libraries:

```bash
composer require symfony/psr-http-message-bridge nyholm/psr7
```

Then create a controller that type-hints `ServerRequestInterface`:

> **Note**: This example assumes your MCP `Server` instance is constructed and bound in a Laravel service provider for dependency injection.

```php
// In a Laravel controller
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class McpController
{
    public function handle(ServerRequestInterface $request, Server $server): ResponseInterface
    {
        // Create the MCP HTTP transport
        $transport = new StreamableHttpTransport($request);

        // Process MCP request and return PSR-7 response
        // Laravel automatically handles PSR-7 responses
        return $server->run($transport);
    }
}

// Route registration
Route::any('/mcp', [McpController::class, 'handle']);
```

### Slim Framework Integration

Slim Framework works natively with PSR-7.

Create a route handler using Slim's built-in factories and container:

```php
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->any('/mcp', function ($request, $response) {
    $server = Server::builder()
        ->setServerInfo('My MCP Server', '1.0.0')
        ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
        ->build();

    $transport = new StreamableHttpTransport($request);

    return $server->run($transport);
});
```

## HTTP Method Handling

The transport handles all HTTP methods automatically:

- **POST**: Send MCP requests
- **GET**: Not implemented (returns 405)
- **DELETE**: End session
- **OPTIONS**: CORS preflight

You should route **all methods** to your MCP endpoint, not just POST.

## Session Management

HTTP transport requires persistent sessions since PHP doesn't maintain state between requests. Unlike STDIO transport
where in-memory sessions work fine, HTTP transport needs a persistent session store:

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

## Recommended Route

It's recommended to mount the MCP endpoint at `/mcp`, but this is not enforced:

```php
// Recommended
Route::any('/mcp', [McpController::class, 'handle']);

// Also valid
Route::any('/', [McpController::class, 'handle']);
Route::any('/api/mcp', [McpController::class, 'handle']);
```

## Testing HTTP Transport

Use the MCP Inspector to test HTTP servers:

```bash
# Start your PHP server
php -S localhost:8000 server.php

# Connect with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000
```
