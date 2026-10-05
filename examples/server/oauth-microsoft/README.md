# OAuth Microsoft Entra ID Example

This example protects an MCP server with access tokens issued by Microsoft Entra ID (formerly Azure AD).
The MCP server is a pure OAuth resource server: it validates tokens, it does not take part in the login flow.

## Features

- JWT validation via `JwtTokenValidator::fromIssuer()`: Entra's metadata and keys are discovered over https and cached
- Audience bound to this server's app registration, so tokens for other APIs (e.g. Microsoft Graph) are rejected
- Every request needs the `mcp.access` scope, enforced with a `ScopePolicy`
- Protected Resource Metadata (RFC 9728) at `/.well-known/oauth-protected-resource/mcp`
- Tools read the caller's claims via `RequestContext::getAccessToken()`

## Azure Setup

### 1. Register the MCP server (the API)

1. [Azure Portal](https://portal.azure.com) > **Entra ID** > **App registrations** > **New registration**, name it `MCP Server`, no redirect URI.
2. Copy **Application (client) ID** → `AZURE_CLIENT_ID` and **Directory (tenant) ID** → `AZURE_TENANT_ID`.
3. **Expose an API**: set the Application ID URI to `api://<client-id>` and add the scope `mcp.access`.
4. **Manifest**: set `"accessTokenAcceptedVersion": 2`, so tokens carry the v2.0 issuer the server expects.

### 2. Register the MCP client

Create a second app registration for the client, add a redirect URI of the client's choice
(public client/native for desktop clients) and grant it the `mcp.access` permission of the `MCP Server` API.

Entra ID supports neither Dynamic Client Registration nor Client ID Metadata Documents, so the
MCP client has to be configured with this pre-registered client ID. Entra also omits
`code_challenge_methods_supported` from its metadata, which MCP clients following the
2026-07-28 specification treat as missing PKCE support and refuse. Serving such clients takes an
authorization server or gateway in front of Entra; that is a deployment concern, not one of this
SDK (see `adr/0002-resource-server-only.md`).

## Quick Start

1. **Configure:**

```bash
cp env.example .env
# set AZURE_TENANT_ID and AZURE_CLIENT_ID
```

2. **Start the services:**

```bash
docker compose up -d
```

3. **Get an access token** for the exposed API, e.g. with the Azure CLI (pre-authorize the
   Azure CLI client `04b07795-8ddb-461a-bbee-02f9e1bf7b46` on the `mcp.access` scope first):

```bash
TOKEN=$(az account get-access-token --scope api://your-client-id/mcp.access --query accessToken -o tsv)
```

4. **Test the MCP server:**

```bash
# Protected Resource Metadata
curl http://localhost:8000/.well-known/oauth-protected-resource/mcp

# Without token: 401 with a WWW-Authenticate challenge
curl -i http://localhost:8000/mcp

# With token
curl -X POST http://localhost:8000/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}'
```

## Files

- `docker-compose.yml` - Docker Compose configuration
- `Dockerfile` - PHP-FPM container
- `nginx/default.conf` - Nginx configuration
- `env.example` - Environment variables template
- `server.php` - MCP server with the authorization middleware
- `McpElements.php` - MCP tools reading the caller's claims

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `AZURE_TENANT_ID` | Yes | Entra ID tenant ID |
| `AZURE_CLIENT_ID` | Yes | Application (client) ID of the MCP server app registration |

## Microsoft Token Claims

| Claim | Description |
|-------|-------------|
| `oid` | Object ID (unique user identifier in tenant) |
| `tid` | Tenant ID |
| `sub` | Subject (pairwise user identifier) |
| `azp` | Client the token was issued to |
| `scp` | Delegated scopes, e.g. `mcp.access` |
| `name` | Display name |
| `preferred_username` | Usually the UPN |

## Troubleshooting

### "Token issuer mismatch"

The token carries the v1.0 issuer `https://sts.windows.net/{tenant}/`. Set
`"accessTokenAcceptedVersion": 2` in the manifest of the `MCP Server` app registration.

### "Token audience mismatch"

The token was issued for another API, e.g. Microsoft Graph (`openid`/`profile` scopes alone yield
Graph tokens). Request the `api://<client-id>/mcp.access` scope instead.

### 403 insufficient_scope

The token lacks `mcp.access` in its `scp` claim; grant the client the API permission.

### Calling Microsoft Graph

The token this server receives is for this server only and must not be passed on. To call Graph
on behalf of the user, exchange it with the On-Behalf-Of flow, which needs a client credential
for the `MCP Server` app registration.

## Cleanup

```bash
docker compose down -v
```
