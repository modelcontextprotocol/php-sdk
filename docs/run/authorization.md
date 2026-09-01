# Authorization

The PHP MCP SDK provides OAuth 2.1 authorization support for HTTP transports, implementing the
[MCP Authorization specification](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).

## Scope: what this SDK does and does not do

The MCP server is an OAuth 2.1 **Resource Server**. It validates the tokens it receives, tells
clients which authorization server issues them, and enforces scopes. **It is not an authorization
server: it does not issue tokens, register clients, or proxy the OAuth flow.**

| Role | What it does | Status |
|------|--------------|--------|
| Resource Server | Validates bearer tokens, serves Protected Resource Metadata (RFC 9728), emits `WWW-Authenticate` challenges, enforces scopes | **Supported** (`AuthorizationMiddleware`, `JwtTokenValidator`, `ProtectedResourceMetadata`, `ScopePolicy`) |
| Delegation / proxy of `/authorize` and `/token`, Dynamic Client Registration | Fronts the authorization server's endpoints | **Not provided** — see [ADR 0002](https://github.com/modelcontextprotocol/php-sdk/blob/main/adr/0002-resource-server-only.md) |
| Authorization Server / Identity Provider | Mints tokens, registers clients, runs login and consent | **Out of scope** — see [ADR 0001](https://github.com/modelcontextprotocol/php-sdk/blob/main/adr/0001-oauth-authorization-server-out-of-scope.md) |

Clients find the authorization server through the Protected Resource Metadata and talk to it
directly. Use an existing IdP (Keycloak, Auth0, Microsoft Entra ID, Okta) or run
`league/oauth2-server` in your own application.

## Overview

Authorization is implemented at the transport level using PSR-15 middleware:

- **AuthorizationMiddleware** - Enforces bearer tokens and scopes, answers 401/403 with a `WWW-Authenticate` challenge and 503 when it cannot check the token
- **ProtectedResourceMetadataMiddleware** - Serves the RFC 9728 metadata document
- **JwtTokenValidator** - Validates JWT access tokens against the authorization server's keys
- **ScopePolicy** - Declares the scopes a request needs, per method and per tool
- **AccessToken** - The validated token, available to handlers via `RequestContext::getAccessToken()`

```
┌─────────────┐     ┌─────────────────────────┐     ┌─────────────────┐
│ MCP Client  │────▶│ AuthorizationMiddleware │────▶│  MCP Handlers   │
└─────────────┘     └─────────────────────────┘     └─────────────────┘
      │                         │
      │ Get token               │ Validate JWT
      ▼                         ▼
┌─────────────┐     ┌───────────────────┐
│ Auth Server │◀────│ JwtTokenValidator │
│  (Keycloak, │     │   + cached JWKS   │
│   Entra ID) │     └───────────────────┘
└─────────────┘
```

## Quick Start

`JwtTokenValidator::fromIssuer()` needs `firebase/php-jwt`, a PSR-6 cache (e.g. `symfony/cache`), a PSR-18 client
(e.g. `symfony/http-client`) and PSR-17 factories (e.g. `nyholm/psr7`). This example also needs `symfony/finder` for
`setDiscovery()` and `laminas/laminas-httphandlerrunner` to send the response:

```bash
composer require firebase/php-jwt symfony/cache symfony/http-client nyholm/psr7 symfony/finder laminas/laminas-httphandlerrunner
```

```php
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$issuer = 'https://auth.example.com/realms/mcp';
$resource = 'https://mcp.example.com/mcp';

// 1. Validate JWTs issued for this server; metadata and keys are discovered and cached
$validator = JwtTokenValidator::fromIssuer(
    issuer: $issuer,
    audience: $resource,
    cache: new FilesystemAdapter('mcp-oauth'),
);

// 2. Describe this resource (RFC 9728)
$metadata = new ProtectedResourceMetadata(
    resource: $resource,
    authorizationServers: [$issuer],
    scopesSupported: ['mcp:read'],
);

// 3. Create transport with middleware; the metadata must be reachable without a token
$request = (new Psr17Factory())->createServerRequestFromGlobals();
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        new CorsMiddleware(),
        new DnsRebindingProtectionMiddleware(allowedHosts: ['mcp.example.com']),
        new ProtectedResourceMetadataMiddleware($metadata),
        new AuthorizationMiddleware($validator, $metadata, new ScopePolicy(default: ['mcp:read'])),
    ],
);

// 4. Run server
$server = Server::builder()
    ->setServerInfo('Protected MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, excludeDirs: ['vendor'])
    ->build();

(new SapiEmitter())->emit($server->run($transport));
```

`DnsRebindingProtectionMiddleware` only allows `localhost`, `127.0.0.1` and `[::1]` by default. Add your public
hostname to `allowedHosts`, or every request gets a 403, see [DNS Rebinding Protection](http.md#dns-rebinding-protection).

The same middleware works with `StatelessHttpTransport`.

## Components

### AuthorizationMiddleware

```php
$middleware = new AuthorizationMiddleware(
    validator: $validator,         // AuthorizationTokenValidatorInterface
    resourceMetadata: $metadata,   // ProtectedResourceMetadata
    scopePolicy: $scopePolicy,     // ScopePolicy|null, no scope checks if null
    maxBodyBytes: $maxBodyBytes,   // default: 4 MiB, applies when the ScopePolicy reads the body
    logger: $logger,               // PSR-3, default: NullLogger, gets a warning for a body over the limit
);
```

**Behavior:**

| Request | Response |
|---------|----------|
| Missing Authorization header or another scheme | 401 with `WWW-Authenticate: Bearer resource_metadata="...", scope="..."` |
| Malformed Bearer token | 400 with `error="invalid_request"` |
| Invalid/expired token, or a signing key whose type or curve `firebase/php-jwt` doesn't support | 401 with `error="invalid_token"` |
| Token cannot be checked, e.g. the authorization server is down | 503 with `Retry-After` and no `WWW-Authenticate` challenge |
| Valid token lacking a required scope | 403 with `error="insufficient_scope"` and every scope the request needs |
| Valid token, body over `maxBodyBytes` while the `ScopePolicy` reads it | 413 with a JSON-RPC error |
| Valid token | Passes to the transport, which hands the `AccessToken` to the handlers |

The `scope` value of a 400 or 401 challenge lists the `scopesSupported` of the metadata. The challenge has no
`scope` if you don't set `scopesSupported`.

The `resource_metadata` URL is derived from the configured resource, never from the request's
`Host` header, so it stays correct behind TLS-terminating proxies.

### ScopePolicy

Declares which scopes a request needs. A request needs the default scopes, plus those of its
JSON-RPC method, plus — for `tools/call` — those of the called tool:

```php
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;

$scopePolicy = new ScopePolicy(
    default: ['mcp:read'],
    methods: ['resources/subscribe' => ['mcp:subscribe']],
    tools: ['delete_file' => ['files:write']],
    implies: ['files:admin' => ['files:write']], // files:admin grants files:write; delete_file also needs mcp:read
);
```

On a missing scope the client gets a 403 naming all scopes the request needs, so it can step up
in a single authorization round trip. Method and tool rules make the middleware read the request
body; it is handed on to the transport unchanged. The middleware reads it first and has its own
`maxBodyBytes`, so when you change the limit, [set the same value on both](http.md#request-body-size-limit).

### ProtectedResourceMetadata

Represents RFC 9728 Protected Resource Metadata:

```php
$metadata = new ProtectedResourceMetadata(
    resource: 'https://mcp.example.com/mcp',          // Required: canonical URI of the MCP server
    authorizationServers: ['https://auth.example.com'], // Required: issuers of accepted tokens
    scopesSupported: ['mcp:read'],                    // Optional: minimal scopes for basic use
    resourceName: 'My MCP Server',                    // Optional
    resourceDocumentation: 'https://example.com/docs', // Optional
);
```

The document is served at `/.well-known/oauth-protected-resource` followed by the resource's
path (RFC 9728, Section 3.1) — `/.well-known/oauth-protected-resource/mcp` in the example above:

```json
{
  "resource": "https://mcp.example.com/mcp",
  "authorization_servers": ["https://auth.example.com"],
  "scopes_supported": ["mcp:read"],
  "bearer_methods_supported": ["header"],
  "resource_name": "My MCP Server",
  "resource_documentation": "https://example.com/docs"
}
```

Resource and authorization server URLs must use https; plain http is only accepted for loopback
hosts (`localhost` and its subdomains such as `shop.localhost`, `127.0.0.0/8`, `::1`) during development.
The host must be a name in ASCII, an internationalized one in punycode, or an IP address in standard form.
The SDK refuses `0.0.0.0`, `::`, an IPv4 address within IPv6 and an IPv4 address in another form than
four decimal numbers (e.g. `127.1`), as these can reach this machine or a private network without looking like it.

### Serving the metadata from a framework

`ProtectedResourceMetadataMiddleware` is a thin path guard around `ProtectedResourceMetadataHandler`,
a plain PSR-15 request handler. When the MCP endpoint lives in a framework, route
`GET /.well-known/oauth-protected-resource/...` (see `$metadata->getMetadataPath()`) to the
handler, converting the framework request to PSR-7 and the response back:

```php
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;

$handler = new ProtectedResourceMetadataHandler($metadata);
$psrResponse = $handler->handle($psrRequest);
```

### JwtTokenValidator

Validates JWT access tokens issued by one authorization server. It checks the `alg` header against
an allowlist, optionally the `typ` header, the signature and time claims, the issuer, and the
audience. A token must have a `kid` header and a numeric `exp` claim, or it gets a 401.

`fromIssuer()` discovers the JWKS URI (RFC 8414, then OpenID Connect Discovery) on the first token
and caches it and the keys in a PSR-6 pool. An unknown key id triggers a rate-limited refetch, so key
rotation does not lock clients out. Keys published without `alg`, as Entra ID does, are matched
against the token's algorithm. Issuer and JWKS URI must use https (loopback hosts excepted) and follow the same rules for their host.

The refetch limit is 10 requests per minute. At the limit, a token with an unknown key id gets a 503
until the minute ends. Tokens with a cached key keep working:

```php
$validator = JwtTokenValidator::fromIssuer(
    issuer: 'https://auth.example.com',  // Expected `iss` claim, matched verbatim
    audience: 'https://mcp.example.com/mcp', // Accepted `aud` value(s)
    cache: $cachePool,                   // PSR-6 CacheItemPoolInterface
    httpClient: null,                    // PSR-18 (auto-discovered)
    requestFactory: null,                // PSR-17 (auto-discovered)
    algorithms: ['RS256'],               // Accepted `alg` header values
    scopeClaim: 'scope',                 // Claim holding the scopes, e.g. `scp` for Entra ID
    tokenType: 'at+jwt',                 // Required `typ` header (RFC 9068), null to skip
    leeway: 30,                          // Tolerated clock skew in seconds
);
```

To manage the keys yourself, pass them to the constructor — any `ArrayAccess` of
`Firebase\JWT\Key` by key id, typically a `Firebase\JWT\CachedKeySet`, or a plain array. If the JWKS
omits `alg`, `CachedKeySet` needs it as last argument. The constructor doesn't check your URLs, so use https
for `$jwksUri` and the issuer yourself:

```php
use Firebase\JWT\CachedKeySet;
use Mcp\Server\Transport\Http\OAuth\RateLimitedJwksClient;

$jwksClient = new RateLimitedJwksClient($httpClient, $cachePool);

$validator = new JwtTokenValidator(
    issuer: 'https://auth.example.com',
    audience: 'https://mcp.example.com/mcp',
    keys: new CachedKeySet($jwksUri, $jwksClient, $requestFactory, $cachePool, 3600, false, 'RS256'),
);
```

!!! warning
    Use `RateLimitedJwksClient` and keep the `rateLimit` argument of `CachedKeySet` at `false`. At its
    own limit, `CachedKeySet` reports the key as missing. During an outage, valid tokens then get a
    401 and clients may ask the user to sign in again.

**The audience must name this MCP server.** Accepting tokens issued for another resource is the
token passthrough the MCP specification forbids. Configure your authorization server to put the
resource URI (or a dedicated API identifier) into `aud`, and set `tokenType: 'at+jwt'` when it
issues RFC 9068 tokens, which keeps ID tokens from being accepted as access tokens.

## Provider Configuration

### Keycloak

Add an audience mapper (`Included Custom Audience`) with the resource URI to a client scope:

```php
$validator = JwtTokenValidator::fromIssuer(
    issuer: 'https://keycloak.example.com/realms/mcp',
    audience: 'https://mcp.example.com/mcp',
    cache: $cachePool,
);
```

### Microsoft Entra ID (Azure AD)

Expose an API scope on the MCP server's app registration and set `"accessTokenAcceptedVersion": 2`:

```php
$validator = JwtTokenValidator::fromIssuer(
    issuer: "https://login.microsoftonline.com/{$tenantId}/v2.0",
    audience: [$clientId, "api://{$clientId}"],
    cache: $cachePool,
    scopeClaim: 'scp',
);
```

Entra ID supports neither Dynamic Client Registration nor Client ID Metadata Documents and omits
`code_challenge_methods_supported` from its metadata, so MCP clients need a pre-registered client
and may refuse it nonetheless; see the Entra example.

### Auth0

```php
$validator = JwtTokenValidator::fromIssuer(
    issuer: 'https://your-tenant.auth0.com/',
    audience: 'https://mcp.example.com/mcp', // the API identifier
    cache: $cachePool,
    tokenType: 'at+jwt',                     // with the RFC 9068 token profile
);
```

### Okta

```php
$validator = JwtTokenValidator::fromIssuer(
    issuer: 'https://your-org.okta.com/oauth2/default',
    audience: 'api://default',
    cache: $cachePool,
);
```

## Reading the Token in Handlers

The validated token reaches handlers through the `RequestContext`. It lives for the request it
arrived with only: it is never written to a session store.

```php
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

#[McpTool(name: 'whoami')]
public function whoami(RequestContext $context): array
{
    $token = $context->getAccessToken(); // null if the transport does not authorize

    return [
        'subject' => $token?->getSubject(),
        'client' => $token?->getClientId(),
        'scopes' => $token?->getScopes() ?? [],
        'email' => $token?->getClaim('email'),
    ];
}
```

Prefer a `ScopePolicy` over scope checks in handlers: only the middleware can answer with the 403
challenge a client steps up from. Use `$token->hasScope()` for decisions that depend on the
arguments beyond the tool name. With a `ScopePolicy`, the token's scopes include those implied by
its hierarchy.

## Sessions and the Principal

A handshake-era session only serves the principal that opened it, as the
[security best practices](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices#session-hijacking)
recommend. The principal is the issuer, client and subject of the access token. They stay the same
when the client refreshes its token, so the session survives the refresh.

When another principal sends the session's `Mcp-Session-Id`, the server answers 404, as for an
unknown session, and a `DELETE` does not end the session.

A token without a `sub` claim only identifies its issuer and client, so all tokens of one client share
their sessions. If your authorization server identifies users by another claim, set the
`StreamableHttpTransport::PRINCIPAL_ATTRIBUTE` request attribute from it. A session opened without a
token only serves requests without a token.

## Authenticating Outside the SDK

If your application or framework already authenticates the request, skip `AuthorizationMiddleware`
and hand the result to the transport as the PSR-7 request attribute `AccessToken::class`. Both HTTP
transports read it from there, so handlers get it through `RequestContext::getAccessToken()` as usual,
and a session only serves the principal of the token that opened it:

```php
use Mcp\Server\Authorization\AccessToken;

$request = $request->withAttribute(AccessToken::class, new AccessToken($scopes, $claims));
$transport = new StreamableHttpTransport($request);
```

If the claims do not identify the user, or there is no token, set the
`StreamableHttpTransport::PRINCIPAL_ATTRIBUTE` attribute as well. It takes a non-empty string, so cast
an integer user id. On a request with a session, the server answers any other value with a 500:

```php
use Mcp\Server\Transport\StreamableHttpTransport;

$request = $request->withAttribute(StreamableHttpTransport::PRINCIPAL_ATTRIBUTE, (string) $userId);
```

## Custom Token Validators

Implement `AuthorizationTokenValidatorInterface` for other token formats, e.g. opaque tokens
checked via token introspection (RFC 7662):

```php
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

final class IntrospectionValidator implements AuthorizationTokenValidatorInterface
{
    public function validate(string $accessToken): AuthorizationResult
    {
        $claims = $this->introspect($accessToken); // your call to the authorization server

        if (true !== ($claims['active'] ?? false) || !in_array('https://mcp.example.com/mcp', (array) ($claims['aud'] ?? []), true)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not active for this resource.');
        }

        return AuthorizationResult::allow(new AccessToken(preg_split('/\s+/', $claims['scope'] ?? '', -1, \PREG_SPLIT_NO_EMPTY), $claims));
    }
}
```

### AuthorizationResult

```php
// Allow access with the validated token
AuthorizationResult::allow(new AccessToken(['mcp:read'], ['sub' => '123']));

// Deny - missing/invalid token (401)
AuthorizationResult::unauthorized('invalid_token', 'Token expired');

// Deny - valid token but insufficient permissions (403)
AuthorizationResult::forbidden('insufficient_scope', 'Requires admin scope', ['admin']);

// Deny - malformed request (400)
AuthorizationResult::badRequest('invalid_request', 'Malformed header');

// Deny - the token cannot be checked right now (503), optional Retry-After in seconds
AuthorizationResult::unavailable(30);
```

## Browser Clients

The default `CorsMiddleware` exposes `WWW-Authenticate` and `Retry-After`, so browser-based clients can
read the challenge, discover the authorization server and wait before they retry. Add their origins to
`allowedOrigins` of both `CorsMiddleware` and `DnsRebindingProtectionMiddleware`, see
[CORS Configuration](http.md#cors-configuration).

`AuthorizationMiddleware` lets `OPTIONS` requests pass without a token, because a browser sends
no credentials with a CORS preflight. Keep `CorsMiddleware` before it in the list, as
`defaultMiddleware()` does, so the preflight response gets its CORS headers.

## Examples

Complete working examples are available in the `examples/server/` directory:

### Keycloak Example

```bash
cd examples/server/oauth-keycloak
docker compose up -d

# Test credentials: demo / demo123
```

See [oauth-keycloak/README.md](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/oauth-keycloak/README.md)

### Microsoft Entra ID Example

```bash
cd examples/server/oauth-microsoft
cp env.example .env
# Edit .env with your Azure values
docker compose up -d
```

See [oauth-microsoft/README.md](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/server/oauth-microsoft/README.md)

## Security Considerations

1. **Always use HTTPS** in production. `ProtectedResourceMetadata` and `fromIssuer()` refuse plain http URLs outside loopback hosts
2. **Bind the audience to this server** — never accept tokens issued for other resources
3. **Never pass the received token on** to upstream APIs; obtain a separate token for them
4. **Use a persistent PSR-6 cache** so keys are not fetched on every request
5. **Never log tokens** - log only non-sensitive claims like subject
6. **Declare scopes in a `ScopePolicy`** for sensitive methods and tools

## Troubleshooting

### "Token issuer mismatch"

The `iss` claim in the token must exactly match the configured issuer URL, including trailing slashes.

### "Token audience mismatch"

The `aud` claim does not contain the configured audience. Some providers use the client ID,
others a custom URI; configure the provider to issue tokens for this server.

### "Token algorithm is not accepted"

The token's `alg` header is not in `algorithms`. Add the algorithm your provider signs with, e.g. `ES256`.

### Metadata or JWKS discovery fails

The server answers with a 503 and `Retry-After`. To fix the cause:

- Ensure network connectivity to the authorization server over https
- The `issuer` in the discovered metadata must match the configured issuer verbatim

### Token expired

- Check clock synchronization between servers
- Use `leeway` to tolerate small clock skew
- Ensure clients refresh tokens before expiration
