# Serving both eras

One endpoint serves both, and the client picks nothing. Every request is classified once,
before anything else looks at it, and routed to the lifecycle it belongs to. The decision is
**body-primary**:

| Evidence | Routed to |
| --- | --- |
| `params._meta` names a modern revision | modern era |
| `params._meta` names a handshake revision | handshake era |
| no such member | handshake era — `initialize` included |
| a notification with no member, under a modern header | modern era |
| `GET` / `DELETE` | handshake era |

The `MCP-Protocol-Version` header never decides. It is cross-checked against the body, and a
request whose header contradicts its `_meta` is refused with `-32020` before either leg sees
it — the check has to happen at the edge, because a body claiming a handshake revision routes
to a leg that has no such check of its own. A modern header on a request carrying no envelope
is refused with `-32602` naming the member it wants.

An unrecognised revision goes to whichever leg can answer it best: claimed in the envelope,
the modern leg answers, naming the modern revisions it serves; named only in a header, the
handshake leg answers, naming the handshake ones.

Both legs come from **one** builder configuration — one registry, one set of handler
instances, one session manager. A tool registered once is reachable from both, and a change
made through one is visible to the other.

## Middleware

The [default middleware stack](../run/http.md#default-middleware) runs at the edge, before
the request's era is known, because what it enforces is true of both. `ProtocolVersionMiddleware`
is not in that stack: the `MCP-Protocol-Version` header rule belongs to the handshake era, so
the transport applies it only to requests it classified as handshake-era traffic, and the
modern leg answers for its own revisions. It is available as
`StreamableHttpTransport::handshakeMiddleware()` and is applied whether or not you replace the
edge stack.

## Serving one era only

To serve the handshake era alone, say so:

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->withoutModernEra()
    ->build();
```

That server refuses a modern claim with `-32022`, naming the handshake revisions it does
serve. `setModernVersions()` narrows the modern leg instead of removing it.

For the opposite — an endpoint that serves the modern era and nothing else — build the
dispatcher on its own and mount it on `StatelessHttpTransport`:

```php
$protocol = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->buildStateless([ProtocolVersion::V2026_07_28]);

(new SapiEmitter())->emit((new StatelessHttpTransport($protocol))->handle($request));
```
