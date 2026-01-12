# OAuth Keycloak Example

This example demonstrates MCP server authorization using Keycloak as the OAuth 2.0 / OpenID Connect provider.

## Features

- JWT token validation with automatic JWKS discovery
- Protected Resource Metadata (RFC 9728) at `/.well-known/oauth-protected-resource`
- MCP tools protected by OAuth authentication
- Pre-configured Keycloak realm with test user

## Quick Start

1. **Start the services:**

```bash
docker compose up -d
```

2. **Wait for Keycloak to be ready** (may take 30-60 seconds):

```bash
docker compose logs -f keycloak
# Wait until you see "Running the server in development mode"
```

3. **Get an access token:**

```bash
# Using Resource Owner Password Credentials (for testing only)
TOKEN=$(curl -s -X POST "http://localhost:8180/realms/mcp/protocol/openid-connect/token" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "client_id=mcp-client" \
  -d "username=demo" \
  -d "password=demo123" \
  -d "grant_type=password" \
  -d "scope=openid mcp" | jq -r '.access_token')

echo $TOKEN
```

4. **Test the MCP server:**

```bash
# Get Protected Resource Metadata
curl http://localhost:8000/.well-known/oauth-protected-resource

# Call MCP endpoint without token (should get 401)
curl -i http://localhost:8000/mcp

# Call MCP endpoint with token
curl -X POST http://localhost:8000/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'
```

5. **Use with MCP Inspector:**

The MCP Inspector doesn't support OAuth out of the box, but you can test using curl or build a custom client.

## Keycloak Configuration

The realm is pre-configured with:

| Item | Value |
|------|-------|
| Realm | `mcp` |
| Client (public) | `mcp-client` |
| Client (resource) | `mcp-server` |
| Test User | `demo` / `demo123` |
| Scopes | `mcp:read`, `mcp:write` |

### Keycloak Admin Console

Access at http://localhost:8180/admin with:
- Username: `admin`
- Password: `admin`

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   MCP Client    │────▶│     Nginx       │────▶│    PHP-FPM      │
│                 │     │   (port 8000)   │     │   MCP Server    │
└─────────────────┘     └─────────────────┘     └─────────────────┘
        │                                               │
        │ Get Token                                     │ Validate JWT
        ▼                                               ▼
┌─────────────────┐                            ┌─────────────────┐
│    Keycloak     │◀───────────────────────────│   JWKS Fetch    │
│   (port 8180)   │                            │                 │
└─────────────────┘                            └─────────────────┘
```

## Files

- `docker-compose.yml` - Docker Compose configuration
- `Dockerfile` - PHP-FPM container with dependencies
- `nginx/default.conf` - Nginx configuration for MCP endpoint
- `keycloak/mcp-realm.json` - Pre-configured Keycloak realm
- `server.php` - MCP server with OAuth middleware
- `McpElements.php` - MCP tools and resources

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `KEYCLOAK_EXTERNAL_URL` | `http://localhost:8180` | Keycloak URL as seen by clients (token issuer) |
| `KEYCLOAK_INTERNAL_URL` | `http://keycloak:8080` | Keycloak URL from within Docker network (for JWKS) |
| `KEYCLOAK_REALM` | `mcp` | Keycloak realm name |
| `MCP_AUDIENCE` | `mcp-server` | Expected JWT audience |

## Troubleshooting

### Token validation fails

1. Ensure Keycloak is fully started (check health endpoint)
2. Verify the token hasn't expired (default: 5 minutes)
3. Check that the audience claim matches `mcp-server`

### Connection refused

1. Wait for Keycloak health check to pass
2. Check Docker network connectivity: `docker compose logs`

### JWKS fetch fails

The MCP server needs to reach Keycloak at `http://keycloak:8080` (Docker network).
For local development outside Docker, use `http://localhost:8180`.

## Cleanup

```bash
docker compose down -v
```
