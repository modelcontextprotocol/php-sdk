# Framework integration

The HTTP transport is a PSR-7 request handler, not a web server. This page
covers how it fits into an application you already have.

## Architecture

The HTTP transport doesn't run its own web server. Instead, it processes PSR-7 requests and returns PSR-7 responses that
your application sends to the client:

```
Your Web App → PSR-7 Request → StreamableHttpTransport → PSR-7 Response → Your Web App
```

This design allows integration with any PHP framework or application that supports PSR-7.

!!! warning
    A streamed response writes its events to the PHP output when your application reads the body. If a framework casts
    the body to a string before it sends the response headers, the client gets the events as `text/html` and without
    `Mcp-Session-Id`. Send the headers before you read the body, as the examples below do. If your server writes the body
    itself, see [Limits under an event loop](server-builder.md#limits-under-an-event-loop).

## Basic Usage (Standalone)

Here's a simplified example using PSR-17 discovery and Laminas emitter. It needs these packages:

```bash
composer require nyholm/psr7 laminas/laminas-httphandlerrunner symfony/finder
```

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
    ->setSession(new FileSessionStore(__DIR__.'/sessions')) // see Session Management below
    ->build();

$transport = new StreamableHttpTransport($request);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
```

To serve this file with Nginx or Apache, see [Web servers](web-servers.md).

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

        // Convert PSR-7 response back to Symfony, without casting the body to a string
        return $httpFoundationFactory->createResponse($psrResponse, streamed: true);
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
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;

class McpController
{
    public function handle(ServerRequestInterface $request, Server $server): Response
    {
        // Create the MCP HTTP transport
        $transport = new StreamableHttpTransport($request);

        // Laravel would cast the body of a returned PSR-7 response to a string
        return (new HttpFoundationFactory())->createResponse($server->run($transport), streamed: true);
    }
}

// Route registration
Route::any('/mcp', [McpController::class, 'handle']);
```

Routes in `routes/web.php` run Laravel's CSRF check, which answers an MCP `POST` with `419` and
`CSRF token mismatch.`. Exclude the path in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['mcp']);
})
```

### Slim Framework Integration

Slim Framework works natively with PSR-7. First install Slim, a PSR-7 implementation and the package that discovery needs:

```bash
composer require slim/slim slim/psr7 symfony/finder
```

Create a route handler using Slim's built-in factories and container:

```php
use Mcp\Server\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->any('/mcp', function ($request, $response) {
    $server = Server::builder()
        ->setServerInfo('My MCP Server', '1.0.0')
        ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
        ->setSession(new FileSessionStore(__DIR__.'/sessions')) // see Session Management below
        ->build();

    $transport = new StreamableHttpTransport($request);

    return $server->run($transport);
});

$app->run();
```

## HTTP Method Handling

The transport handles all HTTP methods automatically:

- **POST**: Send MCP requests
- **GET**: Not implemented (returns 405)
- **DELETE**: End session (returns 405 without sessions)
- **OPTIONS**: CORS preflight

You should route **all methods** to your MCP endpoint, not just POST.

## Session Management

Over HTTP, a server has sessions only when you call `setSession()`. Without a store, it keeps them in the memory of one
process, so pass a store that keeps them between requests. See
[Sessions over HTTP](sessions.md#sessions-over-http). A server without sessions has
[limits](sessions.md#serving-without-sessions).

## Recommended Route

It's recommended to mount the MCP endpoint at `/mcp`, but this is not enforced:

```php
// Recommended
Route::any('/mcp', [McpController::class, 'handle']);

// Also valid
Route::any('/', [McpController::class, 'handle']);
Route::any('/api/mcp', [McpController::class, 'handle']);
```

In Laravel's `routes/web.php`, add the path you choose to the CSRF exceptions.

## Testing HTTP Transport

Use the MCP Inspector to test HTTP servers:

```bash
# Start your PHP server
php -S localhost:8000 server.php

# Connect with MCP Inspector
npx @modelcontextprotocol/inspector --transport http --server-url http://localhost:8000
```
