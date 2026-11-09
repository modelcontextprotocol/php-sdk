# Talking back to the client

MCP supports various ways a server can communicate back to a client on top of the main
request-response flow.

> **Revision `2026-07-28`.** Logging, progress and notifications reach the client on the
> response stream of the request. The client opts into logging and progress per request.
> Sampling and roots work differently on this revision, see their sections.
> Asking the user something has a page of its own: [Asking for input](input-required.md).

## ClientGateway

Every communication back to client is handled using the `Mcp\Server\ClientGateway` and its dedicated methods per
operation. Reach it through method argument injection for `RequestContext`. You can also type-hint a `ClientGateway`
parameter directly. The SDK injects both types and leaves them out of the generated input schema.

Every reference of a MCP element, that translates to an actual method call, can just add an type-hinted argument for the
`RequestContext` and the SDK will take care to include the gateway in the arguments of the method call:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

class MyService
{
    #[McpTool(name: 'my_tool', description: 'My Tool Description')]
    public function myTool(RequestContext $context): string
    {
        $context->getClientGateway()->progress(...);
```

## Request metadata

`RequestContext` also carries what the current request said about itself, which is useful when a feature is only
available from a certain revision on:

```php
use Mcp\Schema\Enum\ProtocolVersion;

if ($context->getProtocolVersion()->isAtLeast(ProtocolVersion::V2026_07_28)) {
    // e.g. a bare list is only valid as `structuredContent` from this revision on
}
```

Two more accessors read what a `2026-07-28` request declared in its `_meta`. On a handshake-era request, they return
`null` and an empty array:

```php
$context->getClientCapabilities();  // the capabilities this request declared
$context->getTraceContext();        // traceparent / tracestate / baggage, verbatim
```

To check a capability in both [protocol eras](../protocol-versions.md), use the probes of `ClientGateway`:
`supportsElicitation()`, `supportsSampling()`, `supportsRoots()`, the sub-capability variants and
`supportsExtension()`. They read what the client declared during the handshake, or what the current
`2026-07-28` request declared. On a server [without sessions](../run/sessions.md#serving-without-sessions), they
return `false` for handshake-era clients, and asking the client throws a `SessionRequiredException`.

On `2026-07-28`, the SDK copies the W3C trace context onto every notification the request sends on its response
stream. The values are plain strings, so reading them needs no OpenTelemetry package.

`RequestContext` also gives you the current request and session with `getRequest()` and `getSession()`, and the
validated OAuth token with `getAccessToken()` (see [Reading the token in handlers](../run/authorization.md#reading-the-token-in-handlers)).

## Sampling

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. It keeps working on a handshake-era connection until then. Revision `2026-07-28` has no server-initiated requests, so on that revision `sample()` and `listRoots()` fail the call with an internal error (`-32603`) that your handler cannot catch. To sample on that revision, [return the ask](input-required.md#what-not-to-call). New integrations should call an LLM provider's API directly instead.

With [sampling](https://modelcontextprotocol.io/specification/2025-11-25/client/sampling) servers can request clients to
execute "completions" or "generations" with a language model for them:

```php
$result = $clientGateway->sample('Roses are red, violets are', 350, 90, ['temperature' => 0.5]);
```

The `sample` method accepts four arguments:

1. `message`, which is **required** and accepts a string, a `TextContent`, `ImageContent` or `AudioContent`, or an array of `Mcp\Schema\Content\SamplingMessage` instances.
2. `maxTokens`, which defaults to `1000`
3. `timeout` in seconds, which defaults to `120`
4. `options` which might include `systemPrompt`, `preferences` for model choice, `includeContext`, `temperature`,
   `stopSequences`, `metadata`, `tools`, and `toolChoice`

Both `tools`/`toolChoice` and `includeContext` are gated on what the client advertised, so check before sending:

```php
if ($clientGateway->supportsSamplingTools()) {
    $result = $clientGateway->sample($messages, options: ['tools' => $tools]);
}
```

A server **must not** send `tools` or `toolChoice` to a client that did not advertise `sampling.tools`. The
`includeContext` values other than `none` are soft-deprecated and should only be sent when the client advertises
`sampling.context` — `supportsSamplingContext()` reports that one.

### Tool loops

When the model wants to call a tool, the result comes back with `stopReason: 'toolUse'` and one or more
`ToolUseContent` blocks. Execute them, then send a follow-up request with the assistant's message and a user message
carrying a matching `ToolResultContent` for every `ToolUseContent`:

```php
use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolResultContent;
use Mcp\Schema\Enum\Role;

$messages[] = new SamplingMessage(Role::Assistant, $result->content);
$messages[] = new SamplingMessage(Role::User, [new ToolResultContent($toolUse->id, [new TextContent($output)])]);
```

The specification is strict about the shape of that exchange: tool results may not be mixed with other content in a
message, and every tool use must be answered before the conversation continues. `sample()` checks these rules before
sending and throws an `InvalidArgumentException` rather than letting the client reject the request with `-32602`.
Use `$result->getContentBlocks()` to iterate the response regardless of whether it holds one block or a list.

[Find more details to sampling payload in the specification.](https://modelcontextprotocol.io/specification/2025-11-25/client/sampling#protocol-messages)

## Roots

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. New integrations should pass directories through tool arguments, resource URIs or server configuration instead. To list roots on that revision, [return the ask](input-required.md#what-not-to-call).

With [roots](https://modelcontextprotocol.io/specification/2025-11-25/client/roots), a server asks the client which
directories or files it may work with:

```php
if ($clientGateway->supportsRoots()) {
    foreach ($clientGateway->listRoots()->roots as $root) {
        // $root->uri, e.g. file:///home/user/project, and the optional $root->name
    }
}
```

`listRoots()` accepts a timeout in seconds, which defaults to `120`.

## Logging

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Logging keeps working until then; new integrations should log to stderr (stdio) or use OpenTelemetry instead.

The [Logging](https://modelcontextprotocol.io/specification/2025-06-18/server/utilities/logging) utility enables servers
to send structured log messages as notification to clients:

```php
use Mcp\Schema\Enum\LoggingLevel;

$clientGateway->log(LoggingLevel::Warning, 'The end is near.');
```

Messages below the client's log level are dropped, the same way as with the
[client logger](logging.md). In the handshake era, the level is `warning` until the client sets one with
`logging/setLevel`, and a server without sessions drops all messages. On revision `2026-07-28`, a request that names no
level receives no log messages, see [Logging](logging.md).

## Progress

With a [Progress](https://modelcontextprotocol.io/specification/2025-06-18/basic/utilities/progress)
notification a server can update a client while an operation is ongoing:

```php
$clientGateway->progress(4.2, 10, 'Downloading needed images.');
```

Progress is opt-in by the client in both eras: it sends a `progressToken` with the request, and without one this
call sends nothing.

## Notification

You can also send any notification that extends the abstract `Mcp\Schema\JsonRpc\Notification` class:

```php
$clientGateway->notify($yourNotification);
```

The SDK doesn't filter these notifications, and the client doesn't opt into them. On revision `2026-07-28`, a notification
on the response stream must relate to the request. Change notifications, such as a changed tool list, belong on
[`subscriptions/listen`](../run/subscriptions.md).
