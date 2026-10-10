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

- **AuthorizationMiddleware** - Enforces bearer tokens and scopes, answers 401/403 with a `WWW-Authenticate` challenge
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

`JwtTokenValidator::fromIssuer()` needs `firebase/php-jwt` and a PSR-6 cache, e.g. `symfony/cache`:

```bash
composer require firebase/php-jwt symfony/cache
```

```php
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
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
$transport = new StreamableHttpTransport(
    $request,
    middleware: [
        ...StreamableHttpTransport::defaultMiddleware(),
        new ProtectedResourceMetadataMiddleware($metadata),
        new AuthorizationMiddleware($validator, $metadata, new ScopePolicy(default: ['mcp:read'])),
    ],
);

// 4. Run server
$server = Server::builder()
    ->setServerInfo('Protected MCP Server', '1.0.0')
    ->setDiscovery(__DIR__, excludeDirs: ['vendor'])
    ->build();

$response = $server->run($transport);
```

The same middleware works with `StatelessHttpTransport`.

## Components

### AuthorizationMiddleware

```php
$middleware = new AuthorizationMiddleware(
    validator: $validator,         // AuthorizationTokenValidatorInterface
    resourceMetadata: $metadata,   // ProtectedResourceMetadata
    scopePolicy: $scopePolicy,     // ScopePolicy|null, no scope checks if null
);
```

**Behavior:**

| Request | Response |
|---------|----------|
| Missing Authorization header or another scheme | 401 with `WWW-Authenticate: Bearer resource_metadata="...", scope="..."` |
| Malformed Bearer token | 400 with `error="invalid_request"` |
| Invalid/expired token | 401 with `error="invalid_token"` |
| Valid token lacking a required scope | 403 with `error="insufficient_scope"` and every scope the request needs |
| Valid token | Passes to the transport, which hands the `AccessToken` to the handlers |

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
    implies: ['files:admin' => ['files:write']], // a token with files:admin may call delete_file
);
```

On a missing scope the client gets a 403 naming all scopes the request needs, so it can step up
in a single authorization round trip. Method and tool rules make the middleware read the request
body; it is handed on to the transport unchanged.

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
hosts (`localhost`, `127.0.0.1`, `::1`) during development.

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
audience.

`fromIssuer()` discovers the JWKS URI (RFC 8414, then OpenID Connect Discovery) on the first token
and caches it and the keys in a PSR-6 pool. An unknown key id triggers a rate-limited refetch, so key
rotation does not lock clients out. Keys published without `alg`, as Entra ID does, are matched
against the token's algorithm. Issuer and JWKS URI must use https (loopback hosts excepted):

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
omits `alg`, `CachedKeySet` needs it as last argument:

```php
use Firebase\JWT\CachedKeySet;

$validator = new JwtTokenValidator(
    issuer: 'https://auth.example.com',
    audience: 'https://mcp.example.com/mcp',
    keys: new CachedKeySet($jwksUri, $httpClient, $requestFactory, $cachePool, 3600, true, 'RS256'),
);
```

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
use Mcp\Capability\Attribute\McpTool;
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

## Authenticating Outside the SDK

If your application or framework already authenticates the request, skip `AuthorizationMiddleware`
and hand the result to the transport as the PSR-7 request attribute `AccessToken::class`. Both HTTP
transports read it from there, so handlers get it through `RequestContext::getAccessToken()` as usual:

```php
use Mcp\Server\Authorization\AccessToken;

$request = $request->withAttribute(AccessToken::class, new AccessToken($scopes, $claims));
$transport = new StreamableHttpTransport($request);
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

        return AuthorizationResult::allow(new AccessToken(explode(' ', $claims['scope'] ?? ''), $claims));
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
```

## Browser Clients

The default `CorsMiddleware` exposes `WWW-Authenticate`, so browser-based clients can read the
challenge and discover the authorization server. Allow their origins via `allowedOrigins`.

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

1. **Always use HTTPS** in production; the SDK refuses plain http URLs outside loopback hosts
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

- Ensure network connectivity to the authorization server over https
- The `issuer` in the discovered metadata must match the configured issuer verbatim

### Token expired

- Check clock synchronization between servers
- Use `leeway` to tolerate small clock skew
- Ensure clients refresh tokens before expiration
