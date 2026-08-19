# What travels with a request

There is no handshake to remember anything, so every request carries what the server needs
to answer it: the revision being spoken, what the client can be asked to do, and who is
asking. The HTTP layer mirrors some of that into headers so an intermediary can route
without parsing the body.

## Per-request metadata

Every request **must** carry two members in `params._meta`:

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

Handlers read the metadata through
[`RequestContext`](../handlers/index.md):

```php
$context->getProtocolVersion();     // the revision serving this request
$context->getClientCapabilities();  // what this client declared, or null in the handshake era
$context->getTraceContext();        // traceparent / tracestate / baggage, verbatim
```

`ClientGateway`'s capability probes — `supportsElicitation()`, `supportsSampling()`,
`supportsRoots()` and the sub-capability variants — read the same declaration, so they work
in both eras.

## Trace context

`traceparent`, `tracestate` and `baggage` are passed through exactly as they arrived, and
echoed onto every notification the request causes, so a span stays joined across the
response stream. Reading them adds no OpenTelemetry dependency — they are strings:

```php
['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']
```

## Mirroring a tool argument into a header

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

## Progress and logging

Both travel on the request's own response stream, and both are opt-in by the client:

- **Progress** — the client sends `_meta.progressToken`; without one, `$gateway->progress()`
  sends nothing.
- **Logging** — the client sends `_meta["io.modelcontextprotocol/logLevel"]`; without it the
  server **must not** emit `notifications/message` at all, and does not. This replaced the
  `logging/setLevel` request the handshake era used.

```php
static function (RequestContext $context): string {
    $client = $context->getClientGateway();
    $client->log(LoggingLevel::Info, 'Reindexing shard 1 of 3');
    $client->progress(1, 3, 'Shard 1 of 3');

    return 'done';
}
```

The server answers with a single JSON object when the handler emits nothing, and opens an
SSE stream when it does — so an error that has to carry a specific status still gets one,
and a handler that talks gets a stream.
