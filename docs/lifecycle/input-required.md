# Asking for input

There are no server-initiated requests in this revision. A server that needs sampling,
elicitation or roots **returns** the ask, and the client retries the original call carrying
the answers. The specification calls this a multi round-trip request (MRTR).

This is the shape to write handlers in even if you also serve handshake-era clients — the
SDK fulfils the same ask over their connection instead. See
[What a handler forks on](#what-a-handler-forks-on).

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

## Reading the answers

`InputContext` hands them back typed — `elicitResult()`, `samplingResult()`,
`rootsResult()` — and returns `null` for an answer that is absent *or* malformed. Both mean
the same thing to a handler: ask again. `response()` is still there for the raw array.

## `requestState`

Whatever the server needs to remember between rounds. It travels through the client, so it
is attacker-controlled on return; `mintRequestState()` seals it with an HMAC and a TTL, and
a state that fails verification never reaches a handler. Configure the key with
`Builder::setRequestState()`:

```php
->setRequestState($_ENV['MCP_REQUEST_STATE_KEY'], ttl: 600)
```

The **same key must reach every process that might serve the retry**. A per-process random
value works only for a single-process deployment. Nothing secret belongs in the payload —
it is signed, not encrypted.

## Capabilities

A server must not ask for input the client cannot provide. The SDK checks each ask against
the request's declared capabilities and answers `-32021` — with the missing set in
`data.requiredCapabilities` — rather than sending an ask that could never be answered.
Url-mode elicitation needs its own `elicitation.url` declaration; a bare `elicitation` means
form mode only.

## What not to call

`ClientGateway::sample()`, `elicit()`, `elicitUrl()` and `listRoots()` belong to the
handshake era. Calling one under this revision raises a `LogicException` naming
`InputRequiredResult` as the replacement.

## What a handler forks on

Nothing. Tools, resources, prompts, structured output, progress and errors do not care
which era called, and neither does the one thing that looks like it should: **asking the
user something**.

Write it the 2026-07-28 way — return an `InputRequiredResult` naming what you need, read the
answer off `RequestContext::getInputContext()` when the call comes back. On a handshake-era
connection the SDK's input-required shim fulfils the same ask over that connection's own
channel: each embedded request goes out as the real `elicitation/create` /
`sampling/createMessage` / `roots/list`, and the handler is re-entered with the answers under
the keys it asked for. It is on by default;
[`examples/server/elicitation`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/elicitation)
and
[`examples/server/client-communication`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/client-communication)
are written this way and name no era anywhere.

Two things to know about it.

**Re-entry is re-execution.** The handler runs again from the top each round, so it has to
re-derive where it is from what came back rather than from anything it kept. That is already
true of the modern era — the client retries the whole call there — so a portable handler is
written that way regardless. It is only new if you were relying on `ClientGateway::elicit()`
suspending mid-body and keeping your locals; that keeps working untouched, since nothing here
runs unless a handler *returns* an ask.

**Each round holds the request open.** The shim waits for the client's answer inside the
originating request, which on a process-per-request runtime means it holds a worker for as
long as the user takes. That is the same cost `ClientGateway::elicit()` already pays on that
leg, but the shim makes it reachable from handlers that never mention it — so size
`setInputRequiredLimits()` against your pool.

```php
$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    // Re-entries per request, and seconds to wait for one answer.
    ->setInputRequiredLimits(maxRounds: 4, roundTimeout: 120)
    ->build();
```

`withoutInputRequiredShim()` turns it off, so such a handler fails on a handshake-era
connection instead of being fulfilled behind your back.
