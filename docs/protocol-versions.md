# Protocol versions

MCP has two eras. Everything up to `2025-11-25` opens with an `initialize` handshake and
can keep state on a session. Protocol revision `2026-07-28` removed both:
everything a server needs to answer a request travels *in* that request, so any process can
answer any request and none of them need to share state.

The SDK serves both, and a server built the ordinary way answers either. This page is the
map; the mechanics live with the task they belong to.

## The two eras

| | Handshake era (`2025-11-25` and earlier) | Modern era (`2026-07-28`) |
| --- | --- | --- |
| Opening | `initialize` / `notifications/initialized` | none |
| Version | negotiated once, then kept on the session or named in the `MCP-Protocol-Version` header | declared on every request |
| Capabilities | exchanged once | declared on every request |
| Discovery | `initialize` result | `server/discover` |
| Sessions | `Mcp-Session-Id`, optional and off by default over HTTP | removed |
| Server → client requests | sent as JSON-RPC requests | returned in the result (MRTR) |
| Change notifications | `resources/subscribe` | `subscriptions/listen` |
| Dispatcher | `Protocol` | `StatelessProtocol` |
| HTTP entry | `StreamableHttpTransport` | the same `StreamableHttpTransport`, or `StatelessHttpTransport` for an endpoint that serves only this era |
| stdio entry | `StdioTransport` | the same `StdioTransport`. The client's first request settles the era |

`ProtocolVersion::isModern()` tells the two apart, and
`Mcp\Schema\Enum\ProtocolVersion::FIRST_MODERN_VERSION` is where the boundary sits.

## Negotiating in the handshake era

Revisions up to `2025-11-25` agree on one during `initialize`. The client names the revision it wants to speak, and
the server answers with the revision the connection will actually use. The client should disconnect if it doesn't
support that revision. This follows the
[version negotiation](https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle#version-negotiation)
section of the specification. The modern era negotiates nothing — each request names its own revision.

The SDK's known revisions live in `Mcp\Schema\Enum\ProtocolVersion`, declared oldest to newest:

```php
use Mcp\Schema\Enum\ProtocolVersion;

ProtocolVersion::latestHandshake();   // newest revision reachable via `initialize`
ProtocolVersion::handshakeVersions(); // every revision the server will negotiate, oldest first
ProtocolVersion::modernVersions();    // every revision served without a handshake
ProtocolVersion::V2025_11_25->isAtLeast(ProtocolVersion::V2025_06_18); // true
```

Comparisons go through declaration order rather than string collation. The identifiers happen to be ISO dates today,
but they are an enumerated set rather than an ordered scalar, so nothing should assume they sort chronologically.

### How the server answers

| Client requests | Server responds with |
| --- | --- |
| A revision the server supports | That same revision |
| An unknown revision string | `ProtocolVersion::latestHandshake()` as a counter-offer |
| A missing or non-string revision | A `-32602` error |
| A modern revision such as `2026-07-28` | `ProtocolVersion::latestHandshake()` as a counter-offer |

A counter-offer is not an error: the client decides whether it can continue on the offered revision or must close the
connection. With a session, the SDK stores the negotiated revision on it under `protocol_version`. Without one, each
request's `MCP-Protocol-Version` header names the revision, see
[Serving without sessions](run/sessions.md#serving-without-sessions).

The last row is not a rejection of an unknown revision — the SDK knows `2026-07-28`, it just cannot be reached through
this handshake. The modern era replaced `initialize` with per-request metadata, so answering with one of its revisions
would leave a connection neither side could use. A client speaking it never gets here: it sends the envelope instead
of an `initialize` request, and the transport routes it to the modern dispatcher before any negotiation is attempted.
See [Serving both eras](run/protocol-eras.md).


### Pinning a revision

On the server, `Mcp\Server\Builder::setProtocolVersion()` pins the handshake to exactly one revision instead of
negotiating across the supported set. The client builder has a method with the same name, described in
[How the client settles on an era](#how-the-client-settles-on-an-era). The pin wins over the client's request,
so a client asking for anything else receives the pinned revision as a counter-offer and has to decide whether
to continue. Leave it unset unless you have a reason to refuse other revisions.

It pins the handshake era only. `setModernVersions()` narrows the revisions the modern era answers for, and
`withoutModernEra()` removes the modern era altogether. See
[Serving one era only](run/protocol-eras.md#serving-one-era-only).

!!! note
    On the Streamable HTTP transport, from revision `2025-06-18`, every handshake-era request after the handshake also
    carries an `MCP-Protocol-Version` header. The transport checks this header against every revision in
    `ProtocolVersion::handshakeVersions()`, and the pin doesn't change that set. See
    [Protocol Version Validation](run/http.md#protocol-version-validation).

## What changes, and where it is written down

The same tool, resource and prompt registrations serve both eras. What changes:

* **[Asking for input](handlers/input-required.md)**: `elicit()` works in both eras. For
  sampling and roots in the modern era, the handler returns the ask, as `sample()` and
  `listRoots()` fail there.
* **[Structured output](servers/tools.md#structured-output)**: from `2026-07-28`, a tool can
  send any JSON value as `structuredContent` ([SEP-2106](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2106)).
  Before it, only a JSON object.
* **[Serving both eras](run/protocol-eras.md)** — what a modern request carries, how one
  endpoint classifies and routes each request, and how to serve one era only.
* **[Caching](run/caching.md)** — the `ttlMs` / `cacheScope` hints a cacheable result must
  carry.
* **[Subscriptions](run/subscriptions.md)** — `subscriptions/listen` and the notification bus
  behind it.
* **[Sessions](run/sessions.md)** — handshake-era only; the modern era has none.
* Logging becomes a per-request opt-in. See [Logging](handlers/logging.md).

## Speaking it from a client

A client speaks both eras out of the box. It prefers `2026-07-28`, and finds out on
`connect()` whether the server does too; nothing about the [client API](client/index.md)
depends on the answer.

```php
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;

$client = Client::builder()
    ->setClientInfo('my-client', '1.0.0')
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($myElicitationHandler)
    ->build();

$client->connect(new HttpTransport('https://example.com/mcp'));

$client->getProtocolVersion(); // 2026-07-28, or 2025-11-25 against an older server
$client->callTool('greet', []);
```

### How the client settles on an era

`connect()` probes with `server/discover`, stamped with the preferred revision, before
anything else — as the specification's backward-compatibility rules for
[stdio](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/stdio#backward-compatibility)
and
[Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#backward-compatibility)
describe.

| The probe gets | The client |
| --- | --- |
| a `DiscoverResult` listing a modern revision it speaks | stays modern, on that revision |
| `-32022` naming a modern revision it speaks | retries the probe with that revision |
| `-32022` naming only handshake revisions | falls back to `initialize` |
| `-32022` naming nothing it speaks | fails the connection |
| a successful HTTP response with a body over the [response body limit](client/transports.md#http-transport) | fails the connection, with no retry |
| a `3xx` HTTP response | fails the connection, with no retry |
| a `401` or `403` HTTP response | fails the connection, with no retry |
| any other error, an HTTP refusal without one, or no answer in time | falls back to `initialize` |
| a `DiscoverResult` listing only handshake revisions | falls back to `initialize` |

The fallback is not keyed to one error code: servers from before the modern era refuse an
unexpected request however they like, or not at all. A refusal costs nothing — the client
falls back as soon as it arrives — but a server that stays silent costs the
[initialization timeout](client/connecting.md#basic-configuration). A server process that exits fails the
attempt outright: an outage is not an answer about the era.

The fallback offers `2025-11-25`; `setFallbackProtocolVersion()` picks another handshake
revision, and `setFallbackProtocolVersion(null)` makes the client modern-only, failing the
connection instead. A handshake revision passed to `setProtocolVersion()` skips the probe
and opens with `initialize`, as a client from before the modern era would.

Once modern, a handful of calls change shape under the same API: `setLoggingLevel()` rides on
every following request instead of sending the removed `logging/setLevel`, `ping()` becomes a
`server/discover`, and `sendRootsListChanged()` sends nothing, since the server asks for roots
with each call that needs them. A server can still ask for sampling and roots by
[returning the ask](handlers/input-required.md); calling out for them through `ClientGateway`
is handshake-era only and fails the call on a modern connection.

What being modern changes underneath:

- **No handshake.** `connect()` sends no `initialize`; the probe's `DiscoverResult` is what
  fills in the server's identity and instructions.
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
them on. Everything else — the probe, the envelope, the skipped handshake, the round-trip
loop — applies to both.

See
[`examples/client/stateless_lifecycle_client.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stateless_lifecycle_client.php)
for a runnable version, described in [Examples](examples.md#modern-era-client).

## What was removed

A request that declares a modern revision and calls one of these methods gets a `-32601` error,
with HTTP status `404` over HTTP:

- `initialize`
- `ping`
- `logging/setLevel` — replaced by `_meta["io.modelcontextprotocol/logLevel"]`
- `resources/subscribe`, `resources/unsubscribe` — replaced by the `resourceSubscriptions`
  filter of `subscriptions/listen`

Over HTTP, the notifications `notifications/initialized` and `notifications/roots/list_changed`
get HTTP status `400` and an empty body.

A client from before the modern era opens with an `initialize` request that has no `_meta`. A
server that serves both eras answers it with the handshake. An endpoint that serves only the
modern era refuses it with `-32022` and names the revisions it speaks.

Also gone: `Mcp-Session-Id`, the HTTP `GET` stream, and SSE resumability (`Last-Event-ID`).
A broken response stream loses the request; the client re-issues it with a new id.

This revision also removes `notifications/elicitation/complete` and the `elicitationId` field, and
moves tasks to the `io.modelcontextprotocol/tasks` extension. The SDK implements none of these.

Error code `-32002` (resource not found) is retired in favour of `-32602`, and must not be
emitted by a server of this revision. The SDK picks the code from the revision serving the
request, so a handshake-era client still gets `-32002`. Error code `-32042` (URL elicitation
required) is retired too, and the SDK doesn't send it.

## Deprecations

Roots, sampling and logging are all **deprecated** as of this revision
([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)). The earliest
removal is the first revision released on or after `2027-07-28`. They remain functional until then; new servers should pass directories
through tool arguments or resource URIs instead of roots, integrate with an LLM provider
directly instead of sampling, and log to `stderr` or OpenTelemetry instead of
`notifications/message`.
