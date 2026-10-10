# Serving both eras

`Server::builder()->build()` produces a server carrying a dispatcher for each
[protocol era](../protocol-versions.md), and `StreamableHttpTransport` decides per request
which of them answers. There is nothing to configure:

```php
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

$request = (new Psr17Factory())->createServerRequestFromGlobals();

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

Plus `Mcp-Method` on every request, and `Mcp-Name` on `tools/call`, `prompts/get`,
`resources/read`, `tasks/get`, `tasks/update` and `tasks/cancel`. A header that disagrees
with the body is refused with `-32020`; a missing required `_meta` member with `-32602`; an
unsupported version with `-32022`, carrying the supported set for the client to retry from.

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

The server refuses a `tools/call` with `-32020` when the header and the argument don't match.
This includes an `Mcp-Param-*` header sent without the argument in the body, and an argument
sent without its header.

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
| a batch with a message that names a modern revision | refused with `-32600` |

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

## Over stdio

`StdioTransport` serves both eras too, but stdio carries one client per process, so the era is
settled once rather than per request: the client's **first request** decides it, by the same
body-primary rule as above.

| Opening request | The connection |
| --- | --- |
| carries a modern revision in `params._meta` | is served by the modern dispatcher from then on |
| anything else — `initialize` above all | runs the handshake, as before `2026-07-28` |

A request from the other era after that is refused rather than served: `initialize` on a modern
connection gets `-32022` naming the modern revisions, an enveloped request on a handshake one
gets `-32600`. That is what a client that probed, gave up waiting and fell back to the handshake
needs to learn that the server settled on the modern era after all.

On a modern connection everything shares the one channel, and requests are served one at a
time: a request's progress and log messages are written as its handler emits them, ahead of its
result, and the next message is read once that result is out. A `subscriptions/listen` is the
long-lived exception: it stays open alongside other requests, each of its messages tagged with
the subscription id, until the client sends `notifications/cancelled` for it, since there is no
stream to close. `setSubscriptionLifetime()` does not apply here. stdio has no headers, so none
of the `Mcp-*` header rules apply.

A server built `withoutModernEra()` refuses a modern opening with `-32022` naming the handshake
revisions, and still accepts the handshake that follows.

## Middleware

The [default middleware stack](http.md#default-middleware) runs for both eras.
`ProtocolVersionMiddleware` runs for handshake-era requests only, see
[Protocol Version Validation](http.md#protocol-version-validation).

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
use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server;
use Mcp\Server\Transport\StatelessHttpTransport;

$request = (new Psr17Factory())->createServerRequestFromGlobals();

$protocol = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->buildStateless([ProtocolVersion::V2026_07_28]);

(new SapiEmitter())->emit((new StatelessHttpTransport($protocol))->handle($request));
```

This endpoint answers a bare `initialize` with `-32022`, naming the revisions it serves.

`StatelessHttpTransport` accepts these constructor arguments:

```php
new StatelessHttpTransport(
    $protocol,         // the result of buildStateless()
    $responseFactory,  // PSR-17, auto-discovered if null
    $streamFactory,    // PSR-17, auto-discovered if null
    $logger,           // PSR-3, default: NullLogger
    $maxBodyBytes,     // default: 4 MiB, a larger body gets 413
    $middleware,       // null installs CorsMiddleware and DnsRebindingProtectionMiddleware
);
```

An empty `middleware` list turns off all middleware, without a warning. The transport answers
`OPTIONS` with `204` and any other method except `POST` with `405`.
