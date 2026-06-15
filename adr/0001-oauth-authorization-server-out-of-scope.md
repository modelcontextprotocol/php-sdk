# 0001 — The MCP server is an OAuth Resource Server, not an Authorization Server

- Status: Accepted
- Date: 2026-06-15

## Context

OAuth 2.1 defines three distinct roles:

| Role | What it does | Status in this SDK | Scope |
|------|--------------|--------------------|-------|
| Resource Server | Validates incoming bearer tokens, serves Protected Resource Metadata (RFC 9728), emits `WWW-Authenticate` | Shipped (`AuthorizationMiddleware`, `JwtTokenValidator`, `ProtectedResourceMetadata`) | **IN scope** |
| Delegation / proxy to an upstream AS | Forwards `/authorize` and `/token` to your existing IdP | Shipped (`OAuthProxyMiddleware`) | **IN scope — delegation ONLY** |
| Authorization Server / Identity Provider (IdP) | Mints its own tokens, registers clients, runs login and consent | Absent | **OUT of scope** |

The SDK repeatedly receives pull requests that move it toward becoming a full OAuth 2.1
**authorization server** — asking the MCP server to mint its own access and refresh tokens,
register clients, and run login and consent flows, that is, to become an Identity Provider.
The most explicit example is [#373](https://github.com/modelcontextprotocol/php-sdk/pull/373)
("[Server] Add native OAuth 2.1 authorization server", ~3,400 lines across 50+ files), but the
direction has crept in incrementally rather than in one PR.

A contributing factor is that the SDK already exposes the *authorization-server endpoints in
proxy form*, and that surface has grown:
[#221](https://github.com/modelcontextprotocol/php-sdk/pull/221) added the OAuth resource-server
middleware, and [#269](https://github.com/modelcontextprotocol/php-sdk/pull/269) added Dynamic
Client Registration (RFC 7591). Today `OAuthProxyMiddleware` answers `/authorize`, `/token`, and
`/.well-known/oauth-authorization-server`, and DCR endpoints exist. The shape of that surface
invites contributors to "finish the job" by backing those endpoints with a real token issuer.
It is not scaffolding to be completed — it is a delegating proxy, and that is the whole of its
intent.

## Decision

**The MCP server is an OAuth 2.1 Resource Server that MAY delegate to an upstream
authorization server. It will NOT issue tokens or act as an Identity Provider.**

Concretely:

- The SDK validates bearer tokens, serves RFC 9728 Protected Resource Metadata, and emits
  `WWW-Authenticate` challenges. This is the Resource Server role and is fully supported.
- The SDK MAY delegate `/authorize` and `/token` to an upstream authorization server via
  `OAuthProxyMiddleware`. This is delegation only: the middleware redirects the browser to
  the upstream `/authorize` endpoint and proxies `/token` requests to the upstream token
  endpoint. It never mints, signs, stores, or rotates tokens of its own.
- The SDK will NOT implement an authorization server: no token issuance, no token signing or
  key management, no login UI, no consent UI, no authorization-code or refresh-token storage,
  no first-party Dynamic Client Registration acting as an issuer.

Pull requests that add authorization-server / IdP behavior are declined by reference to this
ADR.

## Rationale

- **Security liability.** Issuing tokens means owning signing-key generation, storage, and
  rotation; authorization-code and refresh-token persistence; refresh-token rotation and
  replay detection; and consent. A defect in any of these is a credential-issuance
  vulnerability affecting every consumer of the SDK. This is precisely the surface an MCP SDK
  should not own.
- **RFC footprint.** A correct authorization server must implement and keep current with
  RFC 6749 (OAuth 2.0), RFC 7591 (Dynamic Client Registration), RFC 8414 (Authorization
  Server Metadata), PKCE (RFC 7636), and refresh-token rotation guidance, among others. That
  is an open-ended maintenance and conformance burden far outside the SDK's purpose.
- **Mature implementations already exist.** Token issuance is a solved problem.
  `league/oauth2-server` provides it as a PHP library, and every production IdP — Keycloak,
  Auth0, Microsoft Entra ID, Okta — provides it as a service. Re-implementing it inside an
  MCP SDK adds risk without adding value.

## Boundary statement

`OAuthProxyMiddleware` **delegates** to an upstream IdP. It is **not** authorization-server
scaffolding to be completed. Backing its `/authorize` and `/token` endpoints with a
first-party token issuer is out of scope and will be declined.

## Consequences

- The supported authorization architecture is: an external authorization server (your IdP or
  `league/oauth2-server` running in your own application) issues tokens; the MCP server
  validates them as a Resource Server and optionally proxies the OAuth flow to that upstream.
- Contributors get a single, citable ruling for why authorization-server PRs are declined,
  reducing repeated large-PR churn.
- Resource Server and proxy/delegation features remain welcome and supported.

## Alternatives / what to do instead

If you need an authorization server (token issuance, client registration, login, consent), do
**not** add it to this SDK. Instead:

- **Front the MCP server with an existing IdP** — Keycloak, Auth0, Microsoft Entra ID, or
  Okta. Point `JwtTokenValidator` and `ProtectedResourceMetadata` at that issuer, and
  optionally use `OAuthProxyMiddleware` to delegate `/authorize` and `/token` to it.
- **Run `league/oauth2-server` in your own application**, behind the SDK's existing proxy and
  validator seams. The MCP server validates the tokens it issues; it does not issue them
  itself.

See [`../docs/authorization.md`](../docs/authorization.md) for the supported Resource Server
and delegation setup.
