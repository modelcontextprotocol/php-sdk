# OAuth 2.0 Authentication

This document describes how to implement OAuth 2.0 authentication for MCP servers using the PHP SDK, following the [MCP Authorization Specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

## Overview

The MCP OAuth 2.0 implementation follows these standards:

- **OAuth 2.1** (IETF DRAFT) - Core authentication framework
- **RFC 9728** - Protected Resource Metadata
- **RFC 8414** - Authorization Server Metadata Discovery
- **RFC 7591** - Dynamic Client Registration
- **RFC 7592** - Client Registration Management
- **RFC 7662** - Token Introspection
- **RFC 6750** - Bearer Token Usage
- **RFC 8707** - Resource Indicators

## Architecture

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   MCP Client    │────▶│   MCP Server     │────▶│  Auth Server    │
│                 │     │   (Resource)     │     │  (Keycloak,     │
│                 │     │                  │     │   Azure AD...)  │
└─────────────────┘     └──────────────────┘     └─────────────────┘
        │                       │                        │
        │   1. Discovery        │                        │
        │──────────────────────▶│                        │
        │   Protected Resource  │                        │
        │   Metadata (401)      │                        │
        │◀──────────────────────│                        │
        │                       │                        │
        │   2. Get Token        │                        │
        │──────────────────────────────────────────────▶│
        │                       │                        │
        │   3. Token Response   │                        │
        │◀──────────────────────────────────────────────│
        │                       │                        │
        │   4. MCP Request      │                        │
        │   (Bearer Token)      │                        │
        │──────────────────────▶│                        │
        │                       │   5. Validate JWT      │
        │                       │   (via JWKS)          │
        │                       │                        │
        │   6. MCP Response     │                        │
        │◀──────────────────────│                        │
```

## Quick Start

### 1. Configure the Token Authenticator

```php
use Mcp\Server\Auth\JwtTokenAuthenticator;

$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: 'https://your-auth-server/.well-known/jwks.json',
    issuer: 'https://your-auth-server',
    audience: 'your-api-identifier', // MCP server canonical URI
    algorithms: ['RS256'],
);
```

### 2. Define Protected Resource Metadata

```php
use Mcp\Server\Auth\ProtectedResourceMetadata;

$resourceMetadata = new ProtectedResourceMetadata(
    resource: 'https://mcp.example.com',
    authorizationServers: ['https://your-auth-server'],
    scopesSupported: ['mcp:read', 'mcp:write'],
);
```

### 3. Create OAuth2 Configuration

```php
use Mcp\Server\Auth\OAuth2Configuration;

$authConfig = new OAuth2Configuration(
    tokenAuthenticator: $tokenAuthenticator,
    resourceMetadata: $resourceMetadata,
);
```

### 4. Use OAuth2-Enabled Transport

```php
use Mcp\Server\Transport\OAuth2HttpTransport;

$transport = new OAuth2HttpTransport(
    request: $psrServerRequest,
    authConfig: $authConfig,
    logger: $logger,
);

$server->run($transport);
```

## Components

### JwtTokenAuthenticator

Validates JWT access tokens using public keys from a JWKS endpoint.

**Constructor Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `jwksUri` | string | URL to fetch JSON Web Key Set |
| `issuer` | string | Expected token issuer (`iss` claim) |
| `audience` | string\|null | Expected audience (`aud` claim) |
| `algorithms` | string[] | Allowed signing algorithms |
| `leeway` | int | Clock skew tolerance in seconds |
| `jwksCacheTtl` | int | JWKS cache duration in seconds |

**Supported Algorithms:**
- RS256, RS384, RS512 (RSA)
- ES256, ES384, ES512 (ECDSA)

### ProtectedResourceMetadata

Represents the OAuth 2.0 Protected Resource Metadata document (RFC 9728).

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `resource` | string | Canonical URI of the MCP server |
| `authorizationServers` | string[] | List of authorization server issuers |
| `scopesSupported` | string[]\|null | Supported OAuth scopes |
| `bearerMethodsSupported` | string[]\|null | Token delivery methods |
| `resourceName` | string\|null | Human-readable name |

### OAuth2Configuration

Combines all OAuth2 settings for the transport.

**Properties:**

| Property | Type | Description |
|----------|------|-------------|
| `tokenAuthenticator` | TokenAuthenticatorInterface | Token validator |
| `resourceMetadata` | ProtectedResourceMetadata | RFC 9728 metadata |
| `publicPaths` | string[] | Paths that skip authentication |

### OAuth2HttpTransport

HTTP transport with built-in OAuth2 authentication.

**Features:**
- Automatic Protected Resource Metadata endpoint (`/.well-known/oauth-protected-resource`)
- Bearer token extraction from Authorization header
- WWW-Authenticate challenges for 401/403 responses
- Scope-based access control

## Provider Examples

### Microsoft Entra ID (Azure AD)

```php
$tenantId = 'your-tenant-id';
$clientId = 'your-client-id';

$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys",
    issuer: "https://login.microsoftonline.com/{$tenantId}/v2.0",
    audience: $clientId,
);
```

### Auth0

```php
$domain = 'your-domain.auth0.com';

$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: "https://{$domain}/.well-known/jwks.json",
    issuer: "https://{$domain}/",
    audience: 'your-api-identifier',
);
```

### Keycloak

```php
$realm = 'your-realm';
$keycloakUrl = 'https://keycloak.example.com';

$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: "{$keycloakUrl}/realms/{$realm}/protocol/openid-connect/certs",
    issuer: "{$keycloakUrl}/realms/{$realm}",
    audience: 'your-client-id',
);
```

### Okta

```php
$domain = 'your-domain.okta.com';
$authServerId = 'default'; // or custom auth server ID

$tokenAuthenticator = new JwtTokenAuthenticator(
    jwksUri: "https://{$domain}/oauth2/{$authServerId}/v1/keys",
    issuer: "https://{$domain}/oauth2/{$authServerId}",
    audience: 'api://your-api',
);
```

## Error Handling

### 401 Unauthorized

Returned when:
- No Authorization header present
- Invalid token format
- Token validation fails (expired, invalid signature, wrong issuer/audience)

Response includes `WWW-Authenticate` header with:
- `resource_metadata` - URL to Protected Resource Metadata
- `scope` - Required scopes (if configured)

### 403 Forbidden

Returned when the token is valid but lacks required scopes.

Response includes `WWW-Authenticate` header with:
- `error="insufficient_scope"`
- `scope` - Required scopes for the operation

## Implementing Custom Token Validation

Create a custom authenticator by implementing `TokenAuthenticatorInterface`:

```php
use Mcp\Server\Auth\TokenAuthenticatorInterface;
use Mcp\Server\Auth\AuthenticationResult;

class CustomTokenAuthenticator implements TokenAuthenticatorInterface
{
    public function authenticate(string $token, ?string $resource = null): AuthenticationResult
    {
        // Your validation logic here
        // Could use token introspection, database lookup, etc.
        
        if ($valid) {
            return AuthenticationResult::authenticated([
                'sub' => 'user-id',
                'scope' => 'read write',
                // ... other claims
            ]);
        }
        
        return AuthenticationResult::unauthenticated(
            'invalid_token',
            'Token validation failed'
        );
    }
}
```

## Testing with Docker

The SDK includes a Docker setup with Keycloak for local testing:

```bash
cd docker
docker-compose up
```

This starts:
- MCP Server at http://localhost:8080
- Keycloak at http://localhost:8180 (admin/admin)
- MCP Inspector at http://localhost:6274

See `docker/README.md` for detailed instructions.

### Confidential Clients

For server-side applications:

```php
$clientMetadata = ClientRegistration::forConfidentialClient(
    redirectUris: ['https://myapp.example.com/callback'],
    clientName: 'My Server App',
    scope: 'mcp:read mcp:write mcp:admin',
    tokenEndpointAuthMethod: 'client_secret_basic', // or 'client_secret_post', 'private_key_jwt'
);
```

## Token Introspection

For opaque tokens, use RFC 7662 Token Introspection:

```php
use Mcp\Server\Auth\IntrospectionTokenAuthenticator;

$tokenAuthenticator = new IntrospectionTokenAuthenticator(
    introspectionEndpoint: 'https://auth.example.com/oauth/introspect',
    clientId: 'mcp-server',
    clientSecret: 'server-secret',
    expectedAudience: 'https://mcp.example.com',
    logger: $logger,
);
```

## Complete Classes Reference

| Class | RFC | Description |
|-------|-----|-------------|
| `JwtTokenAuthenticator` | - | JWKS-based JWT validation |
| `IntrospectionTokenAuthenticator` | 7662 | Token introspection |
| `ProtectedResourceMetadata` | 9728 | Protected resource metadata |
| `OAuth2Configuration` | - | OAuth2 configuration container |
| `OAuth2HttpTransport` | - | HTTP transport with OAuth2 |
| `WwwAuthenticateChallenge` | 6750 | WWW-Authenticate header builder |
| `AuthorizationServerMetadata` | 8414 | Auth server metadata model |
| `DynamicClientRegistration` | 7591 | Dynamic client registration |
| `ClientRegistration` | 7591 | Client registration metadata |
| `ClientRegistrationResponse` | 7591 | Registration response model |

## Security Considerations

1. **Always use HTTPS** in production
2. **Validate audience** to prevent token misuse
3. **Use short-lived tokens** with refresh tokens
4. **Implement scope-based access control** for sensitive operations
5. **Cache JWKS appropriately** but allow for key rotation
6. **Validate PKCE support** before proceeding with authorization
7. **Store registration tokens securely** if using dynamic client registration

## Further Reading

- [MCP Authorization Specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
- [RFC 9728 - Protected Resource Metadata](https://datatracker.ietf.org/doc/html/rfc9728)
- [RFC 8414 - Authorization Server Metadata](https://datatracker.ietf.org/doc/html/rfc8414)
- [RFC 7591 - Dynamic Client Registration](https://datatracker.ietf.org/doc/html/rfc7591)
- [RFC 7662 - Token Introspection](https://datatracker.ietf.org/doc/html/rfc7662)
- [RFC 6750 - Bearer Token Usage](https://datatracker.ietf.org/doc/html/rfc6750)
- [OAuth 2.1 Draft](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1)

