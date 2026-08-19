# Protocol versions

MCP has two eras. Everything up to `2025-11-25` opens with an `initialize` handshake and
keeps the negotiated revision on a session. Protocol revision `2026-07-28` removed both:
everything a server needs to answer a request travels *in* that request, so any process can
answer any request and none of them need to share state.

The SDK serves both, and a server built the ordinary way answers either. This page is the
map; the mechanics live with the task they belong to.

## The two eras

| | Handshake era (`2025-11-25` and earlier) | Modern era (`2026-07-28`) |
| --- | --- | --- |
| Opening | `initialize` / `notifications/initialized` | none |
| Version | negotiated once, kept on the session | declared on every request |
| Capabilities | exchanged once | declared on every request |
| Discovery | `initialize` result | `server/discover` |
| Sessions | `Mcp-Session-Id` | removed |
| Server → client requests | sent as JSON-RPC requests | returned in the result (MRTR) |
| Change notifications | HTTP `GET` stream, `resources/subscribe` | `subscriptions/listen` |
| Dispatcher | `Protocol` | `StatelessProtocol` |
| HTTP entry | `StreamableHttpTransport` — the same one, for both |

`ProtocolVersion::isModern()` tells the two apart, and
`Mcp\Schema\Enum\ProtocolVersion::FIRST_MODERN_VERSION` is where the boundary sits. How the
handshake era agrees on a revision is
[Protocol version negotiation](run/server-builder.md#protocol-version-negotiation); the
modern era negotiates nothing.

## What changes, and where it is written down

Tools, resources, prompts and their handlers are unaffected — the same registrations serve
either lifecycle. What changes:

* **[Asking for input](handlers/input-required.md)** — a handler that needs elicitation,
  sampling or roots *returns* the ask instead of calling out. Write handlers this way and
  they serve both eras.
* **[Serving both eras](run/protocol-eras.md)** — what a modern request carries, how one
  endpoint classifies and routes each request, and how to serve one era only.
* **[Caching](run/caching.md)** — the `ttlMs` / `cacheScope` hints a cacheable result must
  carry.
* **[Subscriptions](run/subscriptions.md)** — `subscriptions/listen` and the notification bus
  behind it.
* **[Sessions](run/sessions.md)** — handshake-era only; the modern era has none.
* Progress and logging become per-request opt-ins — see
  [Talking back to the client](handlers/client-communication.md#progress) and
  [Logging](handlers/logging.md).

## Speaking it from a client

One line selects the lifecycle; nothing else about the [client API](client/index.md) changes.

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
- **[Multi round-trip calls](handlers/input-required.md) are answered by the client.** A result
  of `resultType: "input_required"` is resolved through the same
  [request handlers](client/server-requests.md) that served server-initiated requests in the
  handshake era, and the call is re-sent with `inputResponses` and the server's `requestState`
  echoed back byte for byte, under a new JSON-RPC id. The caller sees one call and one result.

Headers are an HTTP concern, so a transport opts into them by implementing
`HeaderAwareTransportInterface`; `HttpTransport` does, `StdioTransport` has nothing to carry
them on. Everything else — the envelope, the skipped handshake, the round-trip loop — applies
to both.

See
[`examples/client/stateless_lifecycle_client.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stateless_lifecycle_client.php)
for a runnable version, described in [Examples](examples.md#modern-era-client).

## What was removed

Answered with `404` and `-32601` by a modern server:

- `initialize`, `notifications/initialized`
- `ping`
- `logging/setLevel` — replaced by `_meta["io.modelcontextprotocol/logLevel"]`
- `resources/subscribe`, `resources/unsubscribe` — replaced by the `resourceSubscriptions`
  filter of `subscriptions/listen`
- `notifications/roots/list_changed`

Also gone: `Mcp-Session-Id`, the HTTP `GET` stream, and SSE resumability (`Last-Event-ID`).
A broken response stream loses the request; the client re-issues it with a new id.

Error code `-32002` (resource not found) is retired in favour of `-32602`, and must not be
emitted by a server of this revision. The SDK picks the code from the revision serving the
request, so a handshake-era client still gets `-32002`.

## Deprecations

Roots, sampling and logging are all **deprecated** as of this revision
([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest
removal `2027-07-28`. They remain functional until then; new servers should pass directories
through tool arguments or resource URIs instead of roots, integrate with an LLM provider
directly instead of sampling, and log to `stderr` or OpenTelemetry instead of
`notifications/message`.
