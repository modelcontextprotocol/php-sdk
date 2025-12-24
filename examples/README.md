# MCP SDK Examples

This directory contains various examples of how to use the PHP MCP SDK.

You can run the examples with the dependencies already installed in the root directory of the SDK.
The bootstrapping of the example will choose the used transport based on the SAPI you use.

For running an example, you execute the `server.php` like this:
```bash
# For using the STDIO transport:
php examples/discovery-calculator/server.php

# For using the Streamable HTTP transport:
php -S localhost:8000 examples/discovery-userprofile/server.php
```

You will see debug outputs to help you understand what is happening.

Run with Inspector:

```bash
npx @modelcontextprotocol/inspector php examples/discovery-calculator/server.php
```

## OAuth 2.0 Examples

The SDK supports OAuth 2.0 authentication following the [MCP Authorization Specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization).

### Microsoft Entra ID (Azure AD)

```bash
export MICROSOFT_TENANT_ID=your-tenant-id
export MICROSOFT_CLIENT_ID=your-client-id
export MCP_SERVER_URL=http://localhost:8080

php -S localhost:8080 examples/microsoft-oauth2/server.php
```

### Generic OAuth 2.0 (Auth0, Okta, Keycloak, etc.)

```bash
export OAUTH_JWKS_URI=https://your-provider/.well-known/jwks.json
export OAUTH_ISSUER=https://your-provider
export OAUTH_AUDIENCE=your-api-identifier
export MCP_SERVER_URL=http://localhost:8080

php -S localhost:8080 examples/oauth2-generic/server.php
```

See [docs/oauth2-authentication.md](../docs/oauth2-authentication.md) for full documentation.

## Debugging

You can enable debug output by setting the `DEBUG` environment variable to `1`, and additionally log to a file by
setting the `FILE_LOG` environment variable to `1` as well. A `dev.log` file gets written within the example's
directory.

With the Inspector you can set the environment variables like this:
```bash
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/discovery-calculator/server.php
```
