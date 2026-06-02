# OAuth Authorization Server Example

A self-contained MCP server that is **its own** OAuth 2.1 authorization server: it
registers clients, authorizes users, issues its own RS256 JWT access tokens, and
validates those same tokens to protect the MCP endpoint — with no external
identity provider and no `league/oauth2-server`.

It demonstrates the SDK's native authorization-server layer:

- `GET  /.well-known/oauth-authorization-server` — RFC 8414 metadata (enriched with `registration_endpoint`)
- `GET  /.well-known/oauth-protected-resource` — RFC 9728 metadata
- `GET  /.well-known/jwks.json` — public signing key (JWK Set)
- `POST /register` — RFC 7591 Dynamic Client Registration
- `GET  /authorize` — authorization code grant with mandatory PKCE (S256)
- `POST /token` — `authorization_code` and `refresh_token` grants
- `POST /` — the protected MCP JSON-RPC endpoint (requires `Authorization: Bearer <jwt>`)

> The signing key is generated into `keys/private.pem` on first run, storage is
> in-memory, and login auto-approves a fixed demo user (`DemoResourceOwnerResolver`).
> Replace all three for production: a managed key, the PSR-16 (or your own) stores,
> and a real login/consent backed by your user system.

## Run

```bash
php -S localhost:8000 examples/server/oauth-authorization-server/server.php
```

## Walkthrough (the Claude.ai flow)

```bash
BASE=http://localhost:8000

# 1. Dynamic client registration (public client + PKCE)
curl -s -X POST $BASE/register -H 'Content-Type: application/json' -d '{
  "redirect_uris": ["http://localhost:9999/callback"],
  "token_endpoint_auth_method": "none",
  "client_name": "Demo Client"
}'
# => { "client_id": "...", "redirect_uris": [...], ... }

# 2. Authorize (PKCE). Use a known verifier/challenge pair:
#    verifier  = dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
#    challenge = E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
curl -s -i -c cookies.txt -b cookies.txt \
  "$BASE/authorize?response_type=code&client_id=CLIENT_ID&redirect_uri=http://localhost:9999/callback&code_challenge=E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM&code_challenge_method=S256&scope=mcp:tools&state=xyz"
# => 302 Location: http://localhost:9999/callback?code=CODE&state=xyz

# 3. Exchange the code for tokens
curl -s -X POST $BASE/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "grant_type=authorization_code&code=CODE&redirect_uri=http://localhost:9999/callback&client_id=CLIENT_ID&code_verifier=dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk"
# => { "access_token": "<jwt>", "token_type": "Bearer", "expires_in": 3600, "refresh_token": "..." }

# 4. Call the protected MCP endpoint
curl -s -X POST $BASE/ \
  -H "Authorization: Bearer <jwt>" \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"whoami","arguments":{}}}'
```

A request without a valid token returns `401` with a `WWW-Authenticate` header
pointing at `/.well-known/oauth-protected-resource`.

## Middleware order

The OAuth endpoints are composed before the bearer-protection middleware so they
stay public; only the MCP JSON-RPC path requires a token. `ClientRegistrationMiddleware`
sits outermost of the authorization-server metadata middleware so it can inject
`registration_endpoint` into the served document.
