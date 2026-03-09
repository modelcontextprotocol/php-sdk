# Authorization

The PHP MCP SDK provides OAuth 2.1 authorization support for HTTP transports, implementing the
[MCP Authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Components](#components)
- [JWT Token Validation](#jwt-token-validation)
- [Protected Resource Metadata](#protected-resource-metadata)
- [Custom Token Validators](#custom-token-validators)
- [Scope-Based Access Control](#scope-based-access-control)
- [Examples](#examples)

## Overview

Authorization in MCP is implemented at the transport level using PSR-15 middleware. The SDK provides:

- **AuthorizationMiddleware** - PSR-15 middleware that enforces bearer token authentication
- **ProtectedResourceMetadataMiddleware** - Serves RFC 9728 metadata at well-known endpoints
- **OAuthProxyMiddleware** - Proxies OAuth flows to upstream authorization servers
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
