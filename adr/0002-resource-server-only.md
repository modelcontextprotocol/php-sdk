# 0002 — Resource Server only: no delegation of the OAuth flow

- Status: Accepted
- Date: 2026-10-05
- Amends: [0001](0001-oauth-authorization-server-out-of-scope.md)

## Context

[0001](0001-oauth-authorization-server-out-of-scope.md) kept delegation in scope: the
`OAuthProxyMiddleware` fronted an upstream authorization server's `/authorize` and `/token`
endpoints, and the `ClientRegistrationMiddleware` served Dynamic Client Registration (RFC 7591)
backed by a user-provided registrar. Hardening that surface showed it cannot be made safe
without becoming the authorization server 0001 rules out:

- Holding the server's client credentials, the proxy would have to decide which clients and
  grants it redeems them for and enforce PKCE on their behalf — authorization server logic.
- Its metadata named the MCP server as issuer while the upstream issued the tokens. The
  2026-07-28 specification makes clients validate the `iss` authorization response parameter
  (RFC 9207), so compliant clients reject the proxied flow.
- It could not serve Client ID Metadata Documents, the registration mechanism the
  specification prefers, since the upstream never sees those client ids.
- Forwarding dynamically registered clients under one upstream client id is the confused deputy
  case, for which the specification requires per-client user consent — a consent UI.
- Dynamic Client Registration is deprecated in the 2026-07-28 specification.

None of it is needed: the Protected Resource Metadata points clients at the authorization server
directly, which is how the specification lays out the flow.

## Decision

**The SDK implements the Resource Server role only.** It validates tokens, serves Protected
Resource Metadata, emits `WWW-Authenticate` challenges and enforces scopes. It does not proxy,
front or delegate any authorization server endpoint, and it does not serve client registration.

`OAuthProxyMiddleware`, `ClientRegistrationMiddleware`, `ClientRegistrarInterface` and
`ClientRegistrationException` are removed. Pull requests reintroducing them, or any other
authorization server endpoint, are declined by reference to this ADR and 0001.

## Consequences

- The authorization surface shrinks to what the specification asks of an MCP server, all of
  which can be tested against its MUSTs.
- Identity providers lacking what MCP clients need (Dynamic Client Registration or Client ID
  Metadata Documents, PKCE metadata, RFC 8707 resource indicators) are bridged by a gateway or
  authorization server in front of them — a deployment concern outside this SDK.
