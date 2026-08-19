# The 2026-07-28 lifecycle

Protocol revision `2026-07-28` removed the `initialize` handshake and protocol-level sessions. Everything a
server needs to answer a request now travels *in* that request, which means any process can answer any
request and none of them need to share state.

This guide covers what changes for a server author. Tools, resources, prompts and their handlers are
unaffected — the same registrations serve either lifecycle.

- [The two eras](#the-two-eras)
- [Building a stateless server](#building-a-stateless-server)
- [Per-request metadata](#per-request-metadata)
- [Multi round-trip requests](#multi-round-trip-requests)
- [Progress and logging](#progress-and-logging)
- [Caching](#caching)
- [Subscriptions](#subscriptions)
- [Serving both eras](#serving-both-eras)
- [What was removed](#what-was-removed)

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

`ProtocolVersion::isModern()` tells the two apart, and `Mcp\Schema\Enum\ProtocolVersion::FIRST_MODERN_VERSION`
is where the boundary sits.

## Building a stateless server

There is nothing to build differently. `Builder::build()` produces a `Server` carrying a dispatcher for
each era, and `StreamableHttpTransport` decides per request which of them answers:

```php
use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->addTool(static fn (string $city): string => "17°C in {$city}", name: 'get_weather', description: '…')
    ->build();

(new SapiEmitter())->emit($server->run(new StreamableHttpTransport($request)));
```

That one endpoint answers `initialize` and `server/discover` alike. See
[Serving both eras](#serving-both-eras) for how the decision is made and how to opt out of it.

Modern-era requests accept `POST` only; a `GET` or `DELETE` is a handshake-era session operation and is
routed as one.

A full example lives in [`examples/server/stateless-lifecycle/server.php`](../examples/server/stateless-lifecycle/server.php).

## Per-request metadata

Every request **must** carry two members in `params._meta`, and the HTTP layer mirrors some of them into
headers so an intermediary can route without parsing the body:

| `_meta` key | Required | Header |
| --- | --- | --- |
| `io.modelcontextprotocol/protocolVersion` | yes | `MCP-Protocol-Version` |
| `io.modelcontextprotocol/clientCapabilities` | yes | — |
| `io.modelcontextprotocol/clientInfo` | no | — |
| `io.modelcontextprotocol/logLevel` | no | — |
| `progressToken` | no | — |
| `traceparent`, `tracestate`, `baggage` | no | — |

Plus `Mcp-Method` on every request, and `Mcp-Name` on `tools/call`, `prompts/get` and `resources/read`.
A header that disagrees with the body is refused with `-32020`; a missing required `_meta` member with
`-32602`; an unsupported version with `-32022`, carrying the supported set for the client to retry from.

Handlers read the metadata through `RequestContext`:

```php
$context->getProtocolVersion();     // the revision serving this request
$context->getClientCapabilities();  // what this client declared, or null in the handshake era
$context->getTraceContext();        // traceparent / tracestate / baggage, verbatim
```

`ClientGateway`'s capability probes — `supportsElicitation()`, `supportsSampling()`, `supportsRoots()` and
the sub-capability variants — read the same declaration, so they work in both eras.

### Mirroring a tool argument into a header

A tool parameter annotated with `x-mcp-header` is mirrored into `Mcp-Param-{Name}` by the client, and the
server checks that the two agree:

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

The annotation must name a valid HTTP field, be unique case-insensitively, and sit on a `string`, `integer`
or `boolean` property reachable through `properties` keys alone. `Tool` refuses a definition that breaks
any of those rather than letting it fail later as a header mismatch.

## Multi round-trip requests

There are no server-initiated requests in this revision. A server that needs sampling, elicitation or roots
**returns** the ask, and the client retries the original call carrying the answers.

This is the shape to write handlers in even if you also serve handshake-era clients — the SDK fulfils the
same ask over their connection instead. See [What a handler forks on](#what-a-handler-forks-on).

```php
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;

static function (RequestContext $context): CallToolResult|InputRequiredResult {
    $answer = $context->getInputContext()?->elicitResult('who');

    if (null === $answer) {
        return new InputRequiredResult(
            ['who' => new ElicitRequest('Your name?', $schema)],
            requestState: $context->mintRequestState(['asked' => 'who']),
        );
    }

    return new CallToolResult([new TextContent("Hello, {$answer->content['name']}!")]);
}
```

`tools/call`, `prompts/get` and `resources/read` may answer this way; nothing else may.

**Reading the answers.** `InputContext` hands them back typed — `elicitResult()`, `samplingResult()`,
`rootsResult()` — and returns `null` for an answer that is absent *or* malformed. Both mean the same thing
to a handler: ask again. `response()` is still there for the raw array.

**`requestState`.** Whatever the server needs to remember between rounds. It travels through the client, so
it is attacker-controlled on return; `mintRequestState()` seals it with an HMAC and a TTL, and a state that
fails verification never reaches a handler. Configure the key with `Builder::setRequestState()`:

```php
->setRequestState($_ENV['MCP_REQUEST_STATE_KEY'], ttl: 600)
```

The **same key must reach every process that might serve the retry**. A per-process random value works only
for a single-process deployment. Nothing secret belongs in the payload — it is signed, not encrypted.

**Capabilities.** A server must not ask for input the client cannot provide. The SDK checks each ask against
the request's declared capabilities and answers `-32021` — with the missing set in
`data.requiredCapabilities` — rather than sending an ask that could never be answered. Url-mode elicitation
needs its own `elicitation.url` declaration; a bare `elicitation` means form mode only.

**What not to call.** `ClientGateway::sample()`, `elicit()`, `elicitUrl()` and `listRoots()` belong to the
handshake era. Calling one under this revision raises a `LogicException` naming `InputRequiredResult` as the
replacement.

## Progress and logging

Both travel on the request's own response stream, and both are opt-in by the client:

- **Progress** — the client sends `_meta.progressToken`; without one, `$gateway->progress()` sends nothing.
- **Logging** — the client sends `_meta["io.modelcontextprotocol/logLevel"]`; without it the server **must
  not** emit `notifications/message` at all, and does not.

```php
static function (RequestContext $context): string {
    $client = $context->getClientGateway();
    $client->log(LoggingLevel::Info, 'Reindexing shard 1 of 3');
    $client->progress(1, 3, 'Shard 1 of 3');

    return 'done';
}
```

The server answers with a single JSON object when the handler emits nothing, and opens an SSE stream when it
does — so an error that has to carry a specific status still gets one, and a handler that talks gets a
stream. Trace context from the request is echoed onto every notification it causes.

## Caching

`server/discover`, the four list methods and `resources/read` **must** carry `ttlMs` and `cacheScope`. The
default is `ttlMs: 0, cacheScope: "private"` — conformant, and a flat refusal to let anything be cached.
Say what you actually mean:

```php
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Wire\CachePolicy;

->setCachePolicy(
    CachePolicy::default(30_000)
        ->withMethod('tools/list', 3_600_000, CacheScope::Public)
        ->withMethod('server/discover', 3_600_000, CacheScope::Public),
)
```

`public` lets a shared proxy serve one caller's answer to another, so use it only for results that do not
vary by caller. A `ReadResourceResult` may set its own `ttlMs`/`cacheScope`, which win over the policy.
Results produced by an MRTR retry are never given hints: their inputs are not part of any cache key.

## Subscriptions

`subscriptions/listen` replaces the HTTP `GET` stream and `resources/subscribe`. The client opens a
long-lived POST whose response stream carries the notification types it asked for; the server acknowledges
first with `notifications/subscriptions/acknowledged`, reporting the subset it agreed to honour.

Delivery needs a bus, because the process that publishes and the process holding the stream open are often
not the same one:

```php
use Mcp\Server\Subscription\InMemoryNotificationBus;
use Mcp\Server\Subscription\Psr16NotificationBus;

// stdio, or a persistent runtime where the whole server is one process
->setNotificationBus(new InMemoryNotificationBus())

// PHP-FPM: the publisher and the stream are different workers
->setNotificationBus(new Psr16NotificationBus($cache))
```

Registry changes (`registerTool()`, `unregisterPrompt()`, …) are published automatically. Anything else —
`notifications/resources/updated` above all — is published by the application:

```php
$bus->publish(new ResourceUpdatedNotification('file:///project/config.json'));
```

`Builder::setSubscriptionLifetime()` bounds how long a stream is held before the server closes it
gracefully. The real ceiling is the runtime's: under PHP-FPM a stream cannot outlive `max_execution_time`.
Pass `0` for "until the client or the runtime ends it".

## Serving both eras

One endpoint serves both, and the client picks nothing. Every request is classified once, before anything
else looks at it, and routed to the lifecycle it belongs to. The decision is **body-primary**:

| Evidence | Routed to |
| --- | --- |
| `params._meta` names a modern revision | modern era |
| `params._meta` names a handshake revision | handshake era |
| no such member | handshake era — `initialize` included |
| a notification with no member, under a modern header | modern era |
| `GET` / `DELETE` | handshake era |

The `MCP-Protocol-Version` header never decides. It is cross-checked against the body, and a request whose
header contradicts its `_meta` is refused with `-32020` before either leg sees it — the check has to happen
at the edge, because a body claiming a handshake revision routes to a leg that has no such check of its
own. A modern header on a request carrying no envelope is refused with `-32602` naming the member it wants.

An unrecognised revision goes to whichever leg can answer it best: claimed in the envelope, the modern leg
answers, naming the modern revisions it serves; named only in a header, the handshake leg answers, naming
the handshake ones.

Both legs come from **one** builder configuration — one registry, one set of handler instances, one session
manager. A tool registered once is reachable from both, and a change made through one is visible to the
other.

To serve the handshake era alone, say so:

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->withoutModernEra()
    ->build();
```

That server refuses a modern claim with `-32022`, naming the handshake revisions it does serve.
`setModernVersions()` narrows the modern leg instead of removing it.

For the opposite — an endpoint that serves the modern era and nothing else — build the dispatcher on its
own and mount it on `StatelessHttpTransport`:

```php
$protocol = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->buildStateless([ProtocolVersion::V2026_07_28]);

(new SapiEmitter())->emit((new StatelessHttpTransport($protocol))->handle($request));
```

### What a handler forks on

Nothing. Tools, resources, prompts, structured output, progress and errors do not care which era called,
and neither does the one thing that looks like it should: **asking the user something**.

Write it the 2026-07-28 way — return an `InputRequiredResult` naming what you need, read the answer off
`RequestContext::getInputContext()` when the call comes back. On a handshake-era connection the SDK's
input-required shim fulfils the same ask over that connection's own channel: each embedded request goes
out as the real `elicitation/create` / `sampling/createMessage` / `roots/list`, and the handler is
re-entered with the answers under the keys it asked for. It is on by default;
[`examples/server/elicitation`](../examples/server/elicitation) and
[`examples/server/client-communication`](../examples/server/client-communication) are written this way and
name no era anywhere.

Two things to know about it.

**Re-entry is re-execution.** The handler runs again from the top each round, so it has to re-derive where
it is from what came back rather than from anything it kept. That is already true of the modern era — the
client retries the whole call there — so a portable handler is written that way regardless. It is only new
if you were relying on `ClientGateway::elicit()` suspending mid-body and keeping your locals; that keeps
working untouched, since nothing here runs unless a handler *returns* an ask.

**Each round holds the request open.** The shim waits for the client's answer inside the originating
request, which on a process-per-request runtime means it holds a worker for as long as the user takes.
That is the same cost `ClientGateway::elicit()` already pays on that leg, but the shim makes it reachable
from handlers that never mention it — so size `setInputRequiredLimits()` against your pool.

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    // Re-entries per request, and seconds to wait for one answer.
    ->setInputRequiredLimits(maxRounds: 4, roundTimeout: 120)
    ->build();
```

`withoutInputRequiredShim()` turns it off, so such a handler fails on a handshake-era connection instead of
being fulfilled behind your back.

## Writing a client for this revision

One line selects the lifecycle; nothing else about the API changes.

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

- **No handshake.** `connect()` sends no `initialize`. It asks `server/discover` only for the server's
  identity, and a server that does not answer it still yields a usable connection — the method is
  optional. If discovery *does* report `supportedVersions` and the configured revision is not among
  them, the client moves to a modern revision the server lists, or refuses the connection outright
  rather than talking past it.
- **An envelope on every request**, carrying the revision, the declared capabilities and the client
  identity. The capabilities are what let a server decide, per request, whether it may ask for input.
- **Headers on every POST** — `MCP-Protocol-Version`, `Mcp-Method`, and `Mcp-Name` where the method
  addresses a subject. Arguments annotated with `x-mcp-header` are mirrored into `Mcp-Param-*`, which
  requires the client to have listed the tool first; `tools/list` is what populates that knowledge.
  A tool whose annotations are malformed is dropped from the listing and refused if called, since the
  client cannot produce the headers it demands.
- **Multi round-trip calls are answered by the client.** A result of `resultType: "input_required"` is
  resolved through the same request handlers that served server-initiated requests in the handshake era,
  and the call is re-sent with `inputResponses` and the server's `requestState` echoed back byte for
  byte, under a new JSON-RPC id. The caller sees one call and one result.

Headers are an HTTP concern, so a transport opts into them by implementing `HeaderAwareTransportInterface`;
`HttpTransport` does, `StdioTransport` has nothing to carry them on. Everything else — the envelope, the
skipped handshake, the round-trip loop — applies to both.

See `examples/client/stateless_lifecycle_client.php` for a runnable version.

## What was removed

Answered with `404` and `-32601` by a modern server:

- `initialize`, `notifications/initialized`
- `ping`
- `logging/setLevel` — replaced by `_meta["io.modelcontextprotocol/logLevel"]`
- `resources/subscribe`, `resources/unsubscribe` — replaced by the `resourceSubscriptions` filter of
  `subscriptions/listen`
- `notifications/roots/list_changed`

Also gone: `Mcp-Session-Id`, the HTTP `GET` stream, and SSE resumability (`Last-Event-ID`). A broken
response stream loses the request; the client re-issues it with a new id.

Error code `-32002` (resource not found) is retired in favour of `-32602`, and must not be emitted by a
server of this revision. The SDK picks the code from the revision serving the request, so a handshake-era
client still gets `-32002`.

Roots, sampling and logging are all **deprecated** as of this revision. They remain functional for at least
twelve months; new servers should pass directories through tool arguments or resource URIs instead of roots,
integrate with an LLM provider directly instead of sampling, and log to `stderr` or OpenTelemetry instead of
`notifications/message`.
