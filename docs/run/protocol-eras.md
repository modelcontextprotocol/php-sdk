# Serving both eras

`Server::builder()->build()` produces a server carrying a dispatcher for each
[protocol era](../protocol-versions.md), and `StreamableHttpTransport` decides per request
which of them answers. There is nothing to configure:

```php
use Mcp\Server\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->addTool(static fn (string $city): string => "17°C in {$city}", name: 'get_weather', description: '…')
    ->build();

(new SapiEmitter())->emit($server->run(new StreamableHttpTransport($request)));
```

That one endpoint answers `initialize` and `server/discover` alike. Modern-era requests
accept `POST` only; a `GET` or `DELETE` is a handshake-era session operation and is routed as
one.

A full example lives in
[`examples/server/stateless-lifecycle/server.php`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/stateless-lifecycle),
described in [Examples](../examples.md#the-2026-07-28-lifecycle).

## What a modern request carries

There is no handshake to remember anything, so every request carries what the server needs to
answer it. Two members in `params._meta` are **required**, and the HTTP layer mirrors some of
them into headers so an intermediary can route without parsing the body:

| `_meta` key | Required | Header |
| --- | --- | --- |
| `io.modelcontextprotocol/protocolVersion` | yes | `MCP-Protocol-Version` |
| `io.modelcontextprotocol/clientCapabilities` | yes | — |
| `io.modelcontextprotocol/clientInfo` | no | — |
| `io.modelcontextprotocol/logLevel` | no | — |
| `progressToken` | no | — |
| `traceparent`, `tracestate`, `baggage` | no | — |

Plus `Mcp-Method` on every request, and `Mcp-Name` on `tools/call`, `prompts/get` and
`resources/read`. A header that disagrees with the body is refused with `-32020`; a missing
required `_meta` member with `-32602`; an unsupported version with `-32022`, carrying the
supported set for the client to retry from.

What a handler can read off all this is
[Talking back to the client](../handlers/client-communication.md#request-metadata).

### Mirroring a tool argument into a header

A tool parameter annotated with `x-mcp-header` is mirrored into `Mcp-Param-{Name}` by the
client, and the server checks that the two agree:

```php
->addTool(
    static fn (string $region, string $query): string => …,
    name: 'execute_sql',
    inputSchema: [
        'type' => 'object',
        'properties' => [
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'query' => ['type' => 'string'],
        ],
        'required' => ['region', 'query'],
    ],
)
```

The annotation must name a valid HTTP field, be unique case-insensitively, and sit on a
`string`, `integer` or `boolean` property reachable through `properties` keys alone. `Tool`
refuses a definition that breaks any of those rather than letting it fail later as a header
mismatch. See [Schema generation](../servers/schemas.md) for where a hand-written
`inputSchema` fits.

## How a request is routed

Every request is classified once, before anything else looks at it. The decision is
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

The [default middleware stack](http.md#default-middleware) runs at the edge, before the
request's era is known, because what it enforces is true of both. `ProtocolVersionMiddleware`
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
