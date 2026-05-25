# Transports

Transports handle the communication layer between MCP servers and clients. The PHP MCP SDK provides two main transport
implementations: STDIO for command-line integration and HTTP for web-based communication.

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

$result = $server->run($transport); // Blocks for STDIO, returns a response for HTTP
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
> When using STDIO transport, **never** write to `STDOUT` in your handlers as it's reserved for JSON-RPC communication.
> Use `STDERR` for debugging instead.

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

$status = $server->run($transport);

exit($status); // 0 on clean shutdown, non-zero if STDIN errored
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

The HTTP transport was designed to sit between any PHP project, regardless of the HTTP implementation or how they receive
and process requests and send responses. It provides a flexible architecture that can integrate with any PSR-7 compatible application.

```php
use Psr\Http\Message\ServerRequestInterface;

// PSR-17 factories are automatically discovered
$transport = new StreamableHttpTransport(
    request: $serverRequest,    // PSR-7 server request
    responseFactory: null,      // Optional: PSR-17 response factory (auto-discovered if null)
    streamFactory: null,        // Optional: PSR-17 stream factory (auto-discovered if null)
    logger: $logger             // Optional PSR-3 logger
);
```

### Parameters

- **`request`** (required): `ServerRequestInterface` - The incoming PSR-7 HTTP request
- **`responseFactory`** (optional): `ResponseFactoryInterface` - PSR-17 factory for creating HTTP responses. Auto-discovered if not provided.
- **`streamFactory`** (optional): `StreamFactoryInterface` - PSR-17 factory for creating response body streams. Auto-discovered if not provided.
- **`logger`** (optional): `LoggerInterface` - PSR-3 logger for debugging. Defaults to `NullLogger`.
- **`middleware`** (optional): `iterable<MiddlewareInterface>|null` - PSR-15 middleware chain. `null` (omitted) installs the [default stack](#default-middleware). `[]` disables all defaults — useful when the surrounding application already handles CORS, host validation, etc.

### PSR-17 Auto-Discovery

The transport automatically discovers PSR-17 factory implementations from these popular packages:

- `nyholm/psr7`
- `guzzlehttp/psr7`
- `slim/psr7`
- `laminas/laminas-diactoros`
- And other PSR-17 compatible implementations

```bash
# Install any PSR-17 package - discovery works automatically
composer require nyholm/psr7
```

If auto-discovery fails or you want to use a specific implementation, you can pass factories explicitly:

```php
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17Factory = new Psr17Factory();
$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);
```

### Default Middleware

When the `middleware` argument is omitted (or set to `null`), the transport installs a secure default stack:

| Order | Middleware | Purpose |
|-------|------------|---------|
| 1     | `CorsMiddleware`                    | Applies CORS headers to every response. By default does **not** set `Access-Control-Allow-Origin` (cross-origin requests are blocked). |
| 2     | `DnsRebindingProtectionMiddleware`  | Validates `Origin`/`Host` against an allowlist. Defaults to localhost variants only. |
| 3     | `ProtocolVersionMiddleware`         | Rejects requests carrying an unsupported `MCP-Protocol-Version` header with `400 Bad Request`. |

```php
// Zero-config, secure-by-default — local servers get full protection automatically.
$transport = new StreamableHttpTransport($request);
```

The default stack can be inspected and recomposed via the public factory:

```php
$middleware = StreamableHttpTransport::defaultMiddleware();
```

### CORS Configuration

CORS is handled by `CorsMiddleware`. To enable cross-origin browser requests, configure it explicitly and pass it
in place of (or alongside) the defaults:

```php
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

// Reflect a specific origin
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new CorsMiddleware(allowedOrigins: ['https://myapp.com']),
        new DnsRebindingProtectionMiddleware(),
        new ProtocolVersionMiddleware(),
    ],
);

// Allow all origins (development only)
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new CorsMiddleware(allowedOrigins: ['*']),
        new DnsRebindingProtectionMiddleware(),
        new ProtocolVersionMiddleware(),
    ],
);
```

When the allowlist is a concrete set of origins (not `['*']`), `CorsMiddleware` automatically adds `Vary: Origin`
so shared caches/CDNs do not serve a response generated for one origin to a request from another.

Headers already present on a response (e.g. set by inner middleware) are preserved — `CorsMiddleware` only adds
defaults when they are absent.

> [!IMPORTANT]
> `Access-Control-Allow-Origin: *` is incompatible with credentialed browser requests (those carrying
> `Authorization`, cookies, or client certificates). If your MCP server runs OAuth/Bearer auth and serves
> a browser client, configure `allowedOrigins` with the explicit origin(s) you trust rather than `['*']`.
> The middleware reflects the matching origin verbatim, which is the form browsers accept with credentials.

### DNS Rebinding Protection

`DnsRebindingProtectionMiddleware` validates the `Origin` header against an allowlist (falling back to `Host`
when `Origin` is absent). The default allowlist is localhost-only:

```php
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;

new DnsRebindingProtectionMiddleware(allowedHosts: ['myapp.local', 'mcp.internal']);
```

If the server is fronted by a reverse proxy that already validates `Host`, drop this middleware from the chain
or supply a permissive allowlist.

### Protocol Version Validation

`ProtocolVersionMiddleware` rejects requests whose `MCP-Protocol-Version` header is not in the SDK's supported
set with `400 Bad Request`. Requests without the header pass through, since the `initialize` round-trip and some
legacy clients do not send it.

```php
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;

// Only accept the latest spec version
new ProtocolVersionMiddleware(supportedVersions: [ProtocolVersion::V2025_11_25]);
```

### Custom PSR-15 Middleware

`StreamableHttpTransport` accepts any PSR-15 middleware chain. To extend the defaults, spread them and append
your own middleware — the defaults stay outermost so CORS headers are applied to every response, including
short-circuited ones:

```php
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->hasHeader('Authorization')) {
            return $this->responses->createResponse(401);
        }

        return $handler->handle($request);
    }
}

$transport = new StreamableHttpTransport(
    $request,
    logger: $logger,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        new AuthMiddleware($responseFactory),
    ],
);
```

To selectively drop one default (for example DNS rebinding when running behind a proxy), filter the default list:

```php
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        ...array_filter(
            StreamableHttpTransport::defaultMiddleware(),
            fn ($m) => !$m instanceof DnsRebindingProtectionMiddleware,
        ),
        new AuthMiddleware($responseFactory),
    ],
);
```

Pass `middleware: []` to disable every default and run only your own chain:

```php
$transport = new StreamableHttpTransport(
    $request,
    middleware: [new AuthMiddleware($responseFactory)],
);
```

### Architecture

The HTTP transport doesn't run its own web server. Instead, it processes PSR-7 requests and returns PSR-7 responses that
your application can handle however it needs to:

```
Your Web App → PSR-7 Request → StreamableHttpTransport → PSR-7 Response → Your Web App
```

This design allows integration with any PHP framework or application that supports PSR-7.

### Basic Usage (Standalone)

Here's a simplified example using PSR-17 discovery and Laminas emitter:

```php
use Http\Discovery\Psr17Factory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Session\FileSessionStore;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$server = Server::builder()
    ->setServerInfo('HTTP Server', '1.0.0')
    ->setDiscovery(__DIR__, ['.'])
    ->setSession(new FileSessionStore(__DIR__ . '/sessions')) // HTTP needs persistent sessions
    ->build();

$transport = new StreamableHttpTransport($request);

$response = $server->run($transport);

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
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

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

#### Slim Framework Integration

Slim Framework works natively with PSR-7.

Create a route handler using Slim's built-in factories and container:

```php
use Slim\Factory\AppFactory;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

$app = AppFactory::create();

$app->any('/mcp', function ($request, $response) {
    $server = Server::builder()
        ->setServerInfo('My MCP Server', '1.0.0')
        ->setDiscovery(__DIR__, ['.'])
        ->build();

    $transport = new StreamableHttpTransport($request);

    return $server->run($transport);
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

The choice between STDIO and HTTP transport depends on the client you want to integrate with.
If you are integrating with a client that is running **locally** (like Claude Desktop), use STDIO.
If you are building a server in a distributed environment and need to integrate with a **remote** client, use Streamable HTTP.
