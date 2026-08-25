# Asking for input

Some handlers cannot finish in one go: they need the user to confirm something, fill in a
form, name a directory, or have the client's model draft a paragraph. There are two ways to
write that: ask for it, or return the ask.

## Just asking

For elicitation, ask and use the answer:

```php
static function (RequestContext $context): string {
    $answer = $context->getClientGateway()->elicit('Your name?', $schema, key: 'who');

    return "Hello, {$answer->content['name']}!";
}
```

`key` names an ask, so its answer keeps finding the question it belongs to. Leave it out and
asks are keyed by position — `elicitation_1`, `elicitation_2`, … — which holds as long as the
handler reaches them in the same order every time.

**Write the handler so it can run more than once.** Some clients answer inside the open
request; others answer by re-sending the whole call, which enters your handler again from the
top, once per question. Everything above an ask therefore has to be safe to repeat — put side
effects after the last one, and re-derive where you are from the answers rather than from
anything you kept.

Answers given in an earlier round travel in the [`requestState`](#requeststate), so a handler
asking more than once needs `Builder::setRequestState()` configured.
[`examples/server/elicitation`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/elicitation)
is written this way.

## Returning the ask

The explicit form: **return** an `InputRequiredResult` naming what you need, and read the
answer off `RequestContext` when the call comes back. It is more to write, and it is the only
way to ask several things in **one** round trip, to carry your own state, or to ask for
anything other than elicitation.

> **Revision `2026-07-28`.** Multi round-trip requests (MRTR) are that revision's feature, and
> only there does the protocol itself carry this shape. Over a handshake-era connection the SDK
> emulates it: the input-required shim sends each ask as the real `elicitation/create` /
> `sampling/createMessage` / `roots/list` and re-enters your handler with the answers. That is
> on by default and bounded by `setInputRequiredLimits()` — each round holds the originating
> request open, so it holds a worker for as long as the user takes — and
> `withoutInputRequiredShim()` turns it off, after which such a handler fails there. See
> [Server builder](../run/server-builder.md).

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

`ClientGateway::sample()` and `listRoots()` belong to the handshake era — revision
`2026-07-28` removed both outright, so calling one there raises a `LogicException`. Take what
they gave you from tool arguments, resource URIs or server configuration instead. `elicit()`
and `elicitUrl()` are unaffected: elicitation survived that revision, as an ask carried in the
result.

## Which revision called

Nothing above forks on it. `elicit()` works the same on every revision — only the mechanics
underneath differ, and the SDK picks them. The one thing to keep in mind is the rule already
stated: a handler that asks may be entered again from the top, so let it repeat safely.
