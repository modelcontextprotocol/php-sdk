# OAuth Microsoft Entra ID Example

This example demonstrates MCP server authorization using Microsoft Entra ID (formerly Azure AD) as the OAuth 2.0 / OpenID Connect provider.

## Features

- JWT token validation with Microsoft Entra ID
- Protected Resource Metadata (RFC 9728)
- MCP tools that access Microsoft claims
- Optional Microsoft Graph API integration

## Prerequisites

1. **Azure Subscription** with access to Entra ID
2. **App Registration** in Azure Portal

## Azure Setup

### 1. Create App Registration

1. Go to [Azure Portal](https://portal.azure.com) > **Entra ID** > **App registrations**
2. Click **New registration**
3. Configure:
   - **Name**: `MCP Server`
   - **Supported account types**: Choose based on your needs
   - **Redirect URI**: Leave empty for now (this is a resource server)
4. Click **Register**

### 2. Configure the App

After registration:

1. **Copy values for `.env`**:
   - **Application (client) ID** → `AZURE_CLIENT_ID`
   - **Directory (tenant) ID** → `AZURE_TENANT_ID`

2. **Expose an API** (optional, for custom scopes):
   - Go to **Expose an API**
   - Set **Application ID URI** (e.g., `api://your-client-id`)
   - Add scopes like `mcp.read`, `mcp.write`

3. **Create client secret** (for Graph API calls):
   - Go to **Certificates & secrets**
   - Click **New client secret**
   - Copy the secret value → `AZURE_CLIENT_SECRET`

4. **API Permissions** (for Graph API):
   - Go to **API permissions**
   - Add **Microsoft Graph** > **Delegated permissions**:
     - `User.Read` (for profile)
     - `Mail.Read` (for emails, optional)
   - Grant admin consent if required

### 3. Create a Client App (for testing)

Create a separate app registration for the client:

1. **New registration**:
   - **Name**: `MCP Client`
   - **Redirect URI**: `http://localhost` (Public client/native)

2. **Authentication**:
   - Enable **Allow public client flows** for PKCE

3. **API permissions**:
   - Add permission to your MCP Server app's exposed API

## Quick Start

1. **Copy environment file:**

```bash
cp env.example .env
```

2. **Edit `.env` with your Azure values:**

```bash
AZURE_TENANT_ID=your-tenant-id
AZURE_CLIENT_ID=your-client-id
AZURE_CLIENT_SECRET=your-client-secret  # Optional, for Graph API
```

3. **Start the services:**

```bash
docker compose up -d
```

4. **Get an access token:**

Using Azure CLI:
```bash
# Login
az login

# Get token for your app
TOKEN=$(az account get-access-token \
  --resource api://your-client-id \
  --query accessToken -o tsv)
```

Or using MSAL / OAuth flow in your client application.

5. **Test the MCP server:**

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
│  Microsoft      │◀───────────────────────────│   JWKS Fetch    │
│  Entra ID       │                            │                 │
└─────────────────┘                            └─────────────────┘
        │
        │ (Optional) Graph API
        ▼
┌─────────────────┐
│   Microsoft     │
│   Graph API     │
└─────────────────┘
```

## Files

- `docker-compose.yml` - Docker Compose configuration
- `Dockerfile` - PHP-FPM container
- `nginx/default.conf` - Nginx configuration
- `env.example` - Environment variables template
- `server.php` - MCP server with OAuth middleware
- `McpElements.php` - MCP tools including Graph API integration

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `AZURE_TENANT_ID` | Yes | Azure AD tenant ID |
| `AZURE_CLIENT_ID` | Yes | Application (client) ID |
| `AZURE_CLIENT_SECRET` | No | Client secret for Graph API calls |

## Microsoft Token Structure

Microsoft Entra ID tokens include these common claims:

| Claim | Description |
|-------|-------------|
| `oid` | Object ID (unique user identifier in tenant) |
| `tid` | Tenant ID |
| `sub` | Subject (unique user identifier) |
| `name` | Display name |
| `preferred_username` | Usually the UPN |
| `email` | Email address (if available) |
| `upn` | User Principal Name |

## Troubleshooting

### "Invalid issuer" error

Microsoft uses different issuer URLs depending on the token flow:
- v2.0 endpoint (user/delegated flows): `https://login.microsoftonline.com/{tenant}/v2.0`
- v1.0 endpoint (client credentials flow): `https://sts.windows.net/{tenant}/`

This example **automatically accepts both formats** by configuring multiple issuers in the `JwtTokenValidator`.
Check your token's `iss` claim to verify which format is being used.

### "Invalid audience" error

The `aud` claim must match `AZURE_CLIENT_ID`. For v2.0 tokens with custom scopes,
the audience might be `api://your-client-id`.

### JWKS fetch fails

Microsoft's JWKS endpoint is public. Ensure your container can reach:
`https://login.microsoftonline.com/{tenant}/discovery/v2.0/keys`

### Graph API errors

1. Ensure `AZURE_CLIENT_SECRET` is set
2. Verify API permissions have admin consent
3. Check that the user exists in your tenant

## Security Notes

1. **Never commit `.env` files** - they contain secrets
2. **Use managed identities** in Azure deployments instead of client secrets
3. **Implement proper token refresh** in production clients
4. **Validate scopes** for sensitive operations

## Cleanup

```bash
docker compose down -v
```
