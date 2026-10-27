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
> `withoutInputRequiredShim()` turns it off. The SDK then sends the `InputRequiredResult` as it
> is, and a handshake-era client cannot act on it. See
> [Server builder](../run/server-builder.md).

```php
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\RequestContext;

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

When the call comes back, read the verified payload with `requestState()`:

```php
$state = $context->getInputContext()?->requestState(); // e.g. ['asked' => 'who']
```

The **same key must reach every process that might serve the retry**. A per-process random
value works only for a single-process deployment. Nothing secret belongs in the payload —
it is signed, not encrypted.

### Binding the state to the request

A valid state verifies on any request of the TTL, so a client can echo it on another call,
another tool, or under another user's token. The
[specification](https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr#server-requirements-basic-workflow)
recommends binding it to the authenticated principal and the originating request.

A handler that mints its own state can put that identity into the payload and compare it on
the retry. It cannot do so for the state the SDK mints when it carries answers to the next
round, as for a handler that calls `elicit()` twice. The `binder` argument of
`Builder::setRequestState()` binds both on the modern era: the binder's value is covered by
the MAC of every state the request mints, and a retry that binds to another value is refused
like a forged state. The value itself never goes on the wire.

```php
use Mcp\Server\Stateless\OriginatingRequestBinder;

->setRequestState($_ENV['MCP_REQUEST_STATE_KEY'], ttl: 600, binder: new OriginatingRequestBinder())
```

`OriginatingRequestBinder` binds the method, the tool or prompt name and a digest of its
arguments (or the resource URI), and the token's issuer, client and subject. For anything
else, implement `RequestStateBinderInterface`, which receives the request as the client sent
it and its `AccessToken`. Bind only what stays the same across the rounds of one call;
`_meta` does not.

States minted before the binder was configured no longer verify, so turning it on fails the
calls in flight for at most one TTL.

## Capabilities

A server must not ask for input the client cannot provide. The SDK checks each ask against
the request's declared capabilities and answers `-32021` — with the missing set in
`data.requiredCapabilities` — rather than sending an ask that could never be answered.
Url-mode elicitation needs its own `elicitation.url` declaration; a bare `elicitation` means
form mode only.

`ClientGateway::elicitUrl()` checks this itself. Without `elicitation.url`, it throws an
`InvalidArgumentException` inside your handler. If you don't catch it, the call fails like
any other uncaught exception.

On `2026-07-28`, `supportsSampling()` and `supportsRoots()` read what the request declared.
They can return `true` there, but `sample()` and `listRoots()` still fail (see below).

## What not to call

`ClientGateway::sample()` and `listRoots()` belong to the handshake era. Revision
`2026-07-28` has no server-initiated requests, so calling one there fails the call with an
internal error (`-32603`). The error happens outside your handler, so a `try`/`catch` around
the call never runs. To sample or list roots on that revision,
[return the ask](#returning-the-ask) as an `InputRequiredResult` with a
`CreateSamplingMessageRequest` or `ListRootsRequest`, and read the answer with
`samplingResult()` or `rootsResult()`. `elicit()` and `elicitUrl()` are unaffected: the SDK
turns them into such an ask for you.

Sampling and roots are deprecated since `2026-07-28`
([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)). For new
code, prefer tool arguments, resource URIs or server configuration.

## Which revision called

Nothing above forks on it. `elicit()` works the same on every revision — only the mechanics
underneath differ, and the SDK picks them. The one thing to keep in mind is the rule already
stated: a handler that asks may be entered again from the top, so let it repeat safely.
