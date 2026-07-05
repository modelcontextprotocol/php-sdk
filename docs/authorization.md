# Authorization

The PHP MCP SDK provides OAuth 2.1 authorization support for HTTP transports, implementing the
[MCP Authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

## Table of Contents

- [Scope: what this SDK does and does not do](#scope-what-this-sdk-does-and-does-not-do)
- [Overview](#overview)
- [Quick Start](#quick-start)
- [Components](#components)
- [JWT Token Validation](#jwt-token-validation)
- [Protected Resource Metadata](#protected-resource-metadata)
- [Custom Token Validators](#custom-token-validators)
- [Scope-Based Access Control](#scope-based-access-control)
- [Examples](#examples)

## Scope: what this SDK does and does not do

The MCP server is an OAuth 2.1 **Resource Server**. It validates the tokens it receives and may
delegate the OAuth flow to your upstream Identity Provider. **It is not an authorization server
and it does not issue tokens.**

| Role | What it does | Status | Scope |
|------|--------------|--------|-------|
| Resource Server | Validates incoming bearer tokens, serves Protected Resource Metadata (RFC 9728), emits `WWW-Authenticate` | Supported (`AuthorizationMiddleware`, `JwtTokenValidator`, `ProtectedResourceMetadata`) | **In scope** |
| Delegation / proxy to an upstream AS | Forwards `/authorize` and `/token` to your existing IdP | Supported (`OAuthProxyMiddleware`) | **In scope — delegation only** |
| Authorization Server / Identity Provider | Mints its own tokens, registers clients, runs login and consent | Not implemented | **Out of scope: being an authorization server / issuing tokens is out of scope** |

To issue tokens, front the MCP server with an external IdP (Keycloak, Auth0, Microsoft Entra
ID, Okta) or run `league/oauth2-server` in your own application, and let the MCP server
validate those tokens as a Resource Server. See
[adr/0001-oauth-authorization-server-out-of-scope.md](../adr/0001-oauth-authorization-server-out-of-scope.md).

## Overview

Authorization in MCP is implemented at the transport level using PSR-15 middleware. The SDK provides:

- **AuthorizationMiddleware** - PSR-15 middleware that enforces bearer token authentication
- **ProtectedResourceMetadataMiddleware** - Serves RFC 9728 metadata at well-known endpoints
- **OAuthProxyMiddleware** - Delegates OAuth flows (`/authorize`, `/token`) to your upstream IdP; the SDK never issues tokens itself
- **OAuthRequestMetaMiddleware** - Bridges HTTP OAuth attributes to JSON-RPC request meta
- **JwtTokenValidator** - Validates JWT tokens using JWKS from OAuth 2.0 / OIDC providers
- **OidcDiscovery** - Discovers authorization server metadata from well-known endpoints

```
┌─────────────┐     ┌────────────────────┐     ┌─────────────────┐
│ MCP Client  │────▶│ AuthorizationMiddleware │────▶│  MCP Handlers   │
└─────────────┘     └────────────────────┘     └─────────────────┘
      │                      │
      │                      │ Validate JWT
      ▼                      ▼
┌─────────────┐     ┌─────────────────┐
│ Auth Server │◀────│ JwtTokenValidator│
│  (Keycloak, │     │    + JWKS       │
│   Entra ID) │     └─────────────────┘
└─────────────┘
```

## Quick Start

```php
use Mcp\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\JwksProvider;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\OidcDiscovery;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;

// 1. Set up OIDC discovery and JWKS provider
$discovery = new OidcDiscovery();
$jwksProvider = new JwksProvider($discovery);

// 2. Create JWT validator for your OAuth provider
$validator = new JwtTokenValidator(
    issuer: 'https://auth.example.com/realms/mcp',
    audience: 'mcp-server',
    jwksProvider: $jwksProvider,
);

// 3. Create Protected Resource Metadata (RFC 9728)
$metadata = new ProtectedResourceMetadata(
    authorizationServers: ['https://auth.example.com/realms/mcp'],
    scopesSupported: ['mcp:read', 'mcp:write'],
);

// 4. Create middleware stack
$authMiddleware = new AuthorizationMiddleware(
    validator: $validator,
    resourceMetadata: $metadata,
);

$metadataMiddleware = new ProtectedResourceMetadataMiddleware(
    metadata: $metadata,
);

// 5. Create transport with middleware
$transport = new StreamableHttpTransport(
    $request,
    middlewares: [$metadataMiddleware, $authMiddleware],
);

// 6. Run server
$server = Server::builder()
    ->setServerInfo('Protected MCP Server', '1.0.0')
    ->setDiscovery(__DIR__)
    ->build();

$response = $server->run($transport);
```

## Components

### AuthorizationMiddleware

The main middleware that enforces authentication:

```php
$middleware = new AuthorizationMiddleware(
    validator: $validator,         // AuthorizationTokenValidatorInterface
    resourceMetadata: $metadata,   // ProtectedResourceMetadata instance
    responseFactory: null,         // PSR-17 (auto-discovered)
);
```

**Behavior:**

| Request | Response |
|---------|----------|
| Missing Authorization header | 401 with `WWW-Authenticate: Bearer resource_metadata="..."` |
| Invalid/expired token | 401 with error details |
| Valid token | Passes to next handler with OAuth attributes on request |

### ProtectedResourceMetadataMiddleware

Serves Protected Resource Metadata at configured well-known paths:

```php
$metadataMiddleware = new ProtectedResourceMetadataMiddleware(
    metadata: $metadata,           // ProtectedResourceMetadata instance
    responseFactory: null,         // PSR-17 (auto-discovered)
    streamFactory: null,           // PSR-17 (auto-discovered)
);
```

### JwtTokenValidator

Validates JWT access tokens:

```php
$validator = new JwtTokenValidator(
    issuer: 'https://auth.example.com',  // Expected issuer claim
    audience: 'mcp-server',              // Expected audience (string or array)
    jwksProvider: $jwksProvider,          // JwksProviderInterface
    jwksUri: null,                       // Explicit JWKS URI (auto-discovered)
    algorithms: ['RS256', 'RS384'],      // Allowed algorithms
    scopeClaim: 'scope',                 // Claim name for scopes
);
```

**Request Attributes:**

After successful validation, these attributes are added to the request:

| Attribute | Description |
|-----------|-------------|
| `oauth.claims` | All JWT claims as array |
| `oauth.scopes` | Extracted scopes as array |
| `oauth.subject` | The `sub` claim |
| `oauth.client_id` | The `client_id` claim (if present) |
| `oauth.authorized_party` | The `azp` claim (if present) |

### ProtectedResourceMetadata

Represents RFC 9728 Protected Resource Metadata:

```php
$metadata = new ProtectedResourceMetadata(
    authorizationServers: [              // Required: authorization server URLs
        'https://auth.example.com',
    ],
    scopesSupported: [                   // Optional: supported scopes
        'mcp:read',
        'mcp:write',
    ],
    resource: 'https://mcp.example.com', // Optional: resource identifier
    resourceName: 'My MCP Server',       // Optional: human-readable name
    metadataPaths: [                     // Paths to serve metadata (default: /.well-known/oauth-protected-resource)
        '/.well-known/oauth-protected-resource',
    ],
    extra: [                             // Optional: additional fields
        'custom_field' => 'value',
    ],
);
```

### OidcDiscovery

Discovers OAuth/OIDC server metadata:

```php
$discovery = new OidcDiscovery(
    httpClient: null,      // PSR-18 (auto-discovered)
    requestFactory: null,  // PSR-17 (auto-discovered)
    cache: $cache,         // PSR-16 cache (optional)
    cacheTtl: 3600,        // Cache TTL
);

// Discover metadata
$metadata = $discovery->discover('https://auth.example.com/realms/mcp');

// Get specific endpoints
$jwksUri = $discovery->getJwksUri($issuer);
$tokenEndpoint = $discovery->getTokenEndpoint($issuer);
$authEndpoint = $discovery->getAuthorizationEndpoint($issuer);
```

### JwksProvider

Fetches and caches JWKS key sets:

```php
$jwksProvider = new JwksProvider(
    discovery: $discovery,     // OidcDiscoveryInterface
    httpClient: null,          // PSR-18 (auto-discovered)
    requestFactory: null,      // PSR-17 (auto-discovered)
    cache: $cache,             // PSR-16 cache (optional)
    cacheTtl: 3600,            // JWKS cache TTL
);
```

## JWT Token Validation

### Keycloak

```php
$validator = new JwtTokenValidator(
    issuer: 'https://keycloak.example.com/realms/mcp',
    audience: 'mcp-server',
    jwksProvider: $jwksProvider,
);
```

### Microsoft Entra ID (Azure AD)

```php
$tenantId = 'your-tenant-id';
$clientId = 'your-client-id';

$validator = new JwtTokenValidator(
    issuer: "https://login.microsoftonline.com/{$tenantId}/v2.0",
    audience: $clientId,
    jwksProvider: $jwksProvider,
);
```

### Auth0

```php
$validator = new JwtTokenValidator(
    issuer: 'https://your-tenant.auth0.com/',
    audience: 'https://api.example.com',
    jwksProvider: $jwksProvider,
);
```

### Okta

```php
$validator = new JwtTokenValidator(
    issuer: 'https://your-org.okta.com/oauth2/default',
    audience: 'api://default',
    jwksProvider: $jwksProvider,
);
```

## Protected Resource Metadata

The `ProtectedResourceMetadataMiddleware` serves Protected Resource Metadata at configured paths, enabling clients to discover the authorization server:

```json
{
  "authorization_servers": ["https://auth.example.com/realms/mcp"],
  "scopes_supported": ["mcp:read", "mcp:write"],
  "resource": "https://mcp.example.com/mcp"
}
```

Clients request this from `/.well-known/oauth-protected-resource` before authenticating.

### WWW-Authenticate Header

On 401 responses, the middleware includes:

```
WWW-Authenticate: Bearer resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource",
                         scope="mcp:read mcp:write"
```

### Serving the metadata endpoint as a controller

`ProtectedResourceMetadataMiddleware` is a thin path-guard adapter over
`ProtectedResourceMetadataHandler`, a plain PSR-15
`RequestHandlerInterface` (`handle(ServerRequestInterface): ResponseInterface`). That
signature is the same shape as a Symfony/Laravel callable controller, so if your framework
already owns routing you can mount the handler directly instead of routing the well-known
`GET` through the MCP transport. The handler decides *what* to return; your router decides
*when* to call it.

```php
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;

$handler = new ProtectedResourceMetadataHandler(new ProtectedResourceMetadata(
    authorizationServers: ['https://auth.example.com'],
    scopesSupported: ['mcp:read', 'mcp:write'],
    resource: 'https://mcp.example.com/mcp',
));
```

**Symfony** — convert the request to PSR-7 and the response back with
[`symfony/psr-http-message-bridge`](https://symfony.com/doc/current/components/psr7.html):

```php
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/.well-known/oauth-protected-resource', methods: ['GET'])]
public function metadata(Request $request): Response
{
    $psrRequest = (new PsrHttpFactory())->createRequest($request);

    return (new HttpFoundationFactory())->createResponse($this->handler->handle($psrRequest));
}
```

**Laravel** — type-hint the PSR-7 request and return the PSR-7 response directly:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// Route::get('/.well-known/oauth-protected-resource', [MetadataController::class, 'metadata']);
public function metadata(ServerRequestInterface $request): ResponseInterface
{
    return $this->handler->handle($request);
}
```

The same pattern applies to the other endpoint-style OAuth middleware
(`ClientRegistrationMiddleware`, `OAuthProxyMiddleware`), which likewise self-select on a path
and short-circuit — they can be exposed as handlers the same way. See the framework-agnostic
[`oauth-resource-metadata`](../examples/server/oauth-resource-metadata/) example.

## Custom Token Validators

Implement `AuthorizationTokenValidatorInterface` for custom validation:

```php
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;

final class ApiKeyValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        private array $validKeys,
    ) {}

    public function validate(string $accessToken): AuthorizationResult
    {
        if (!isset($this->validKeys[$accessToken])) {
            return AuthorizationResult::unauthorized(
                'invalid_token',
                'Unknown API key'
            );
        }

        $keyInfo = $this->validKeys[$accessToken];

        return AuthorizationResult::allow([
            'api_key.name' => $keyInfo['name'],
            'api_key.scopes' => $keyInfo['scopes'],
        ]);
    }
}

// Usage
$validator = new ApiKeyValidator([
    'sk_live_abc123' => ['name' => 'Production', 'scopes' => ['read', 'write']],
]);
```

### AuthorizationResult

Factory methods for different outcomes:

```php
// Allow access with attributes
AuthorizationResult::allow(['user_id' => '123']);

// Deny - missing/invalid token (401)
AuthorizationResult::unauthorized('invalid_token', 'Token expired');

// Deny - valid token but insufficient permissions (403)
AuthorizationResult::forbidden('insufficient_scope', 'Requires admin scope', ['admin']);

// Deny - malformed request (400)
AuthorizationResult::badRequest('invalid_request', 'Malformed header');
```

## Scope-Based Access Control

### Checking Scopes in Handlers

```php
#[McpTool(name: 'admin_action')]
public function adminAction(RequestContext $context): array
{
    $scopes = $context->getRequest()?->getAttribute('oauth.scopes') ?? [];

    if (!in_array('mcp:admin', $scopes, true)) {
        throw new \RuntimeException('Admin scope required');
    }

    // Perform admin action
    return ['status' => 'success'];
}
```

### Using JwtTokenValidator::requireScopes

```php
// In a custom middleware or handler
$result = $validator->validate($token);

if ($result->isAllowed()) {
    // Check for specific scopes
    $result = $validator->requireScopes($result, ['mcp:write']);
}

if (!$result->isAllowed()) {
    // Handle insufficient scope (returns 403)
}
```

## Examples

Complete working examples are available in the `examples/server/` directory:

### Keycloak Example

```bash
cd examples/server/oauth-keycloak
docker-compose up -d

# Test credentials: demo / demo123
```

See [oauth-keycloak/README.md](../examples/server/oauth-keycloak/README.md)

### Microsoft Entra ID Example

```bash
cd examples/server/oauth-microsoft
cp env.example .env
# Edit .env with your Azure credentials
docker-compose up -d
```

See [oauth-microsoft/README.md](../examples/server/oauth-microsoft/README.md)

## Security Considerations

1. **Always use HTTPS** in production for token transmission
2. **Validate audience claims** to prevent token confusion attacks
3. **Use short-lived tokens** and implement token refresh
4. **Cache JWKS** to reduce latency but allow for key rotation
5. **Never log tokens** - log only non-sensitive claims like subject
6. **Validate scopes** before performing sensitive operations

## Troubleshooting

### "Invalid issuer" error

The `iss` claim in the token must exactly match the configured issuer URL, including trailing slashes.

### "Invalid audience" error

Check the `aud` claim matches your configured audience. Some providers use the client ID, others use a custom URI.

### JWKS fetch timeout

- Ensure network connectivity to the authorization server
- Consider using a cache to reduce dependency on the auth server
- Check firewall rules allow outbound HTTPS

### Token expired

- Check clock synchronization between servers
- Tokens typically have a 5-minute clock skew tolerance
- Ensure clients refresh tokens before expiration
