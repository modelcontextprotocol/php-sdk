# HTTP Transport

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

## Parameters

- **`request`** (required): `ServerRequestInterface` - The incoming PSR-7 HTTP request
- **`responseFactory`** (optional): `ResponseFactoryInterface` - PSR-17 factory for creating HTTP responses. Auto-discovered if not provided.
- **`streamFactory`** (optional): `StreamFactoryInterface` - PSR-17 factory for creating response body streams. Auto-discovered if not provided.
- **`logger`** (optional): `LoggerInterface` - PSR-3 logger for debugging. Defaults to `NullLogger`.
- **`middleware`** (optional): `iterable<MiddlewareInterface>|null` - PSR-15 middleware chain. `null` (omitted) installs the [default stack](#default-middleware). `[]` disables all defaults — useful when the surrounding application already handles CORS, host validation, etc.
- **`maxBodyBytes`** (optional): `int` - Upper bound on the POST request body read, in bytes. Defaults to 4 MiB (`StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES`). See [Request Body Size Limit](#request-body-size-limit).

## PSR-17 Auto-Discovery

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

## Default Middleware

When the `middleware` argument is omitted (or set to `null`), the transport installs a secure default stack:

| Order | Middleware | Purpose |
|-------|------------|---------|
| 1     | `CorsMiddleware`                    | Applies CORS headers to every response. By default does **not** set `Access-Control-Allow-Origin` (cross-origin requests are blocked). |
| 2     | `DnsRebindingProtectionMiddleware`  | Validates `Origin`/`Host` against an allowlist. Defaults to localhost variants only. |

```php
// Zero-config, secure-by-default — local servers get full protection automatically.
$transport = new StreamableHttpTransport($request);
```

The default stack can be inspected and recomposed via the public factory:

```php
$middleware = StreamableHttpTransport::defaultMiddleware();
```

These run at the edge, before the request's protocol era is known, because what they enforce is true of
both eras. `ProtocolVersionMiddleware` is not in that stack: the `MCP-Protocol-Version` header rule belongs
to the handshake era, so the transport applies it only to requests it classified as handshake-era traffic,
and the modern leg answers for its own revisions. It is available as
`StreamableHttpTransport::handshakeMiddleware()` and is applied whether or not you replace the edge stack.
See [Serving both eras](protocol-eras.md).

## CORS Configuration

CORS is handled by `CorsMiddleware`. To enable cross-origin browser requests, configure it explicitly and pass it
in place of (or alongside) the defaults:

```php
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;

// Reflect a specific origin
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new CorsMiddleware(allowedOrigins: ['https://myapp.com']),
        new DnsRebindingProtectionMiddleware(),
    ],
);

// Allow all origins (development only)
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new CorsMiddleware(allowedOrigins: ['*']),
        new DnsRebindingProtectionMiddleware(),
    ],
);
```

When the allowlist is a concrete set of origins (not `['*']`), `CorsMiddleware` automatically adds `Vary: Origin`
so shared caches/CDNs do not serve a response generated for one origin to a request from another.

Headers already present on a response (e.g. set by inner middleware) are preserved — `CorsMiddleware` only adds
defaults when they are absent.

!!! warning
    `Access-Control-Allow-Origin: *` is incompatible with credentialed browser requests (those carrying
    `Authorization`, cookies, or client certificates). If your MCP server runs OAuth/Bearer auth and serves
    a browser client, configure `allowedOrigins` with the explicit origin(s) you trust rather than `['*']`.
    The middleware reflects the matching origin verbatim, which is the form browsers accept with credentials.

## DNS Rebinding Protection

`DnsRebindingProtectionMiddleware` validates the `Origin` header against an allowlist (falling back to `Host`
when `Origin` is absent). The default allowlist is localhost-only:

```php
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;

new DnsRebindingProtectionMiddleware(allowedHosts: ['myapp.local', 'mcp.internal']);
```

If the server is fronted by a reverse proxy that already validates `Host`, drop this middleware from the chain
or supply a permissive allowlist.

## Protocol Version Validation

`ProtocolVersionMiddleware` rejects requests whose `MCP-Protocol-Version` header is not in the SDK's supported
set with `400 Bad Request`. Requests without the header pass through, since the `initialize` round-trip and some
legacy clients do not send it.

It is applied by the transport itself, to handshake-era traffic only — do not add it to a custom `middleware`
list, where it would run before the era is classified and reject every modern-era request. Construct it
yourself only to narrow the supported set on a server that also pins its handshake:

```php
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;

// Only accept the latest spec version
new ProtocolVersionMiddleware(supportedVersions: [ProtocolVersion::V2025_11_25]);
```

The default set is `ProtocolVersion::handshakeVersions()` — every revision the server can actually negotiate over
`initialize`, rather than every revision the enum declares. A request without the header is treated as
`ProtocolVersion::DEFAULT_HEADER_VERSION` (`2025-03-26`), the revision that introduced both Streamable HTTP and the
header itself, so a header-less request cannot be newer than that.

This header check is separate from, and happens after, the handshake itself. See
[Protocol versions](../protocol-versions.md#negotiating-in-the-handshake-era) for how the revision is agreed in the
first place. Being separate also means it is unaffected by `setProtocolVersion()`: the middleware validates against
the set it was constructed with, not against the revision a given session negotiated, so a server that pins the
handshake has to pass that revision here as well.

Modern revisions never reach it. A `2026-07-28` request declares its revision in `params._meta` rather than in a
header, and is routed away from this check entirely — see
[Serving both eras](protocol-eras.md).

## Request Body Size Limit

`StreamableHttpTransport` caps the POST body it reads to guard against memory exhaustion from an oversized or
unbounded (chunked) payload. The default cap is 4 MiB. A body over the cap is rejected with `413` and never reaches
message parsing.

```php
use Mcp\Server\Transport\StreamableHttpTransport;

// Raise the cap to 16 MiB
$transport = new StreamableHttpTransport($request, maxBodyBytes: 16 * 1024 * 1024);
```

When the request stream advertises a size, the transport rejects it up-front. Otherwise (e.g. chunked transfer with
unknown size) the body is read incrementally and aborted as soon as it crosses the cap, so an unbounded stream cannot
exhaust memory. A value below `1` throws `InvalidArgumentException`.

## JSON-RPC Batch Size Limit

A JSON-RPC batch (top-level array) is capped at 100 messages. Oversized batches are rejected before any
message is constructed, so a single small request cannot amplify into arbitrarily many operations. The cap
lives on `Mcp\Schema\MessageFactory` and is not currently configurable through the builder — a server built
with `Server::builder()` always uses the default of 100.

Single-message vs batch is determined from the decoded JSON type — a JSON object is a single message, a JSON array
is a batch. Scalars, empty payloads, and non-object batch elements are returned as `InvalidInputMessageException`
entries (the existing per-message error contract), not parse errors or crashes. A `maxBatchSize` below `1` throws
`InvalidArgumentException`.

## Custom PSR-15 Middleware

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
