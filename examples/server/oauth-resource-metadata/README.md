# OAuth Resource Metadata — endpoint as a callable controller

This example shows how to serve the OAuth 2.0 Protected Resource Metadata endpoint
([RFC 9728](https://datatracker.ietf.org/doc/html/rfc9728),
`/.well-known/oauth-protected-resource`) **without** wiring it into the MCP transport's
PSR-15 middleware chain.

The metadata concern is exposed as a plain
[`ProtectedResourceMetadataHandler`](../../../src/Server/Transport/Http/OAuth/ProtectedResourceMetadataHandler.php) —
a PSR-15 `RequestHandlerInterface` (`handle(ServerRequestInterface): ResponseInterface`).
That signature is structurally identical to a Symfony/Laravel callable controller, so the
same object works three ways:

1. **Inside the MCP transport** — wrapped by `ProtectedResourceMetadataMiddleware` (the
   OAuth examples, e.g. `oauth-keycloak`).
2. **As a bare PSR-7 handler** — this example's front controller routes the well-known
   `GET` straight to `$metadataHandler->handle($request)`; `/mcp` keeps using the transport.
3. **As a framework controller** — convert the framework request to PSR-7 and the returned
   PSR-7 response back. See [`docs/authorization.md`](../../../docs/authorization.md).

## Run it

```bash
php -S localhost:8000 examples/server/oauth-resource-metadata/server.php
```

Fetch the metadata (served by the handler, no transport, no middleware):

```bash
curl -s http://localhost:8000/.well-known/oauth-protected-resource | jq
```

```json
{
  "authorization_servers": ["https://auth.example.com"],
  "scopes_supported": ["mcp:read", "mcp:write"],
  "resource": "http://localhost:8000/mcp",
  "resource_name": "OAuth Resource Metadata Example",
  "resource_documentation": "https://modelcontextprotocol.io"
}
```

The MCP endpoint still works over the transport as usual:

```bash
curl -s -X POST http://localhost:8000/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1.0"}}}'
```
