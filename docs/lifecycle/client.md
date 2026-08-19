# Clients on this revision

One line selects the lifecycle; nothing else about the [client API](../client/index.md)
changes.

```php
$client = Client::builder()
    ->setClientInfo('my-client', '1.0.0')
    ->setProtocolVersion(ProtocolVersion::V2026_07_28)
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($myElicitationHandler)
    ->build();

$client->connect(new HttpTransport('https://example.com/mcp'));

$client->callTool('greet', []);
```

What that changes underneath:

- **No handshake.** `connect()` sends no `initialize`. It asks `server/discover` only for the
  server's identity, and a server that does not answer it still yields a usable connection —
  the method is optional. If discovery *does* report `supportedVersions` and the configured
  revision is not among them, the client moves to a modern revision the server lists, or
  refuses the connection outright rather than talking past it.
- **An envelope on every request**, carrying the revision, the declared capabilities and the
  client identity. The capabilities are what let a server decide, per request, whether it may
  ask for input.
- **Headers on every POST** — `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` where the
  method addresses a subject. Arguments annotated with `x-mcp-header` are mirrored into
  `Mcp-Param-*`, which requires the client to have listed the tool first; `tools/list` is what
  populates that knowledge. A tool whose annotations are malformed is dropped from the listing
  and refused if called, since the client cannot produce the headers it demands.
- **[Multi round-trip calls](input-required.md) are answered by the client.** A result of
  `resultType: "input_required"` is resolved through the same
  [request handlers](../client/server-requests.md) that served server-initiated requests in the
  handshake era, and the call is re-sent with `inputResponses` and the server's `requestState`
  echoed back byte for byte, under a new JSON-RPC id. The caller sees one call and one result.

Headers are an HTTP concern, so a transport opts into them by implementing
`HeaderAwareTransportInterface`; `HttpTransport` does, `StdioTransport` has nothing to carry
them on. Everything else — the envelope, the skipped handshake, the round-trip loop — applies
to both.

See
[`examples/client/stateless_lifecycle_client.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stateless_lifecycle_client.php)
for a runnable version, described in [Examples](../examples.md#modern-era-client).
