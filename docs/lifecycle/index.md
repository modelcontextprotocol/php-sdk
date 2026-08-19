# The 2026-07-28 lifecycle

Protocol revision `2026-07-28` removed the `initialize` handshake and protocol-level
sessions. Everything a server needs to answer a request now travels *in* that request,
which means any process can answer any request and none of them need to share state.

Tools, resources, prompts and their handlers are unaffected — the same registrations
serve either lifecycle. What changes is the wire around them, and that is what this
section covers.

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
`Mcp\Schema\Enum\ProtocolVersion::FIRST_MODERN_VERSION` is where the boundary sits.

## Building a stateless server

There is nothing to build differently. `Builder::build()` produces a `Server` carrying a
dispatcher for each era, and `StreamableHttpTransport` decides per request which of them
answers:

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
[Serving both eras](serving-both-eras.md) for how the decision is made and how to opt out
of it.

Modern-era requests accept `POST` only; a `GET` or `DELETE` is a handshake-era session
operation and is routed as one.

A full example lives in
[`examples/server/stateless-lifecycle/server.php`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/stateless-lifecycle),
and [Examples](../examples.md#the-2026-07-28-lifecycle) walks through it.

## The rest of this section

* **[What travels with a request](requests.md)** — the `_meta` envelope every request
  must carry, the headers that mirror it, and how progress and logging become opt-in.
* **[Asking for input](input-required.md)** — multi round-trip requests: how a handler
  asks for elicitation, sampling or roots when it cannot interrupt the call to do so.
* **[Caching](caching.md)** — the `ttlMs` / `cacheScope` hints a cacheable result must
  carry, and how to say what you actually mean by them.
* **[Subscriptions](subscriptions.md)** — `subscriptions/listen` and the notification bus
  that makes delivery work across processes.
* **[Serving both eras](serving-both-eras.md)** — how one endpoint classifies and routes
  each request, and how to serve only one era.
* **[Clients on this revision](client.md)** — the one builder line that selects it, and
  what it changes underneath.

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

Roots, sampling and logging are all **deprecated** as of this revision. They remain
functional for at least twelve months; new servers should pass directories through tool
arguments or resource URIs instead of roots, integrate with an LLM provider directly
instead of sampling, and log to `stderr` or OpenTelemetry instead of
`notifications/message`.
