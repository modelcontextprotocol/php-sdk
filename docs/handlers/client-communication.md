# Talking back to the client

MCP supports various ways a server can communicate back to a client on top of the main
request-response flow.

> **Protocol revision `2026-07-28`.** Logging, progress and notifications work as described
> below on every revision; under the modern lifecycle they travel on the request's own
> response stream and the client opts into each. Sampling is the exception — see its section.
> Asking the user something has a page of its own: [Asking for input](input-required.md).

## ClientGateway

Every communication back to client is handled using the `Mcp\Server\ClientGateway` and its dedicated methods per
operation. Reach it through method argument injection for `RequestContext`. (A `ClientGateway`-typed parameter is
injected too, but unlike `RequestContext` it is not excluded from the generated input schema, so it would show up as
an argument of your tool.)

Every reference of a MCP element, that translates to an actual method call, can just add an type-hinted argument for the
`RequestContext` and the SDK will take care to include the gateway in the arguments of the method call:

```php
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

class MyService
{
    #[McpTool(name: 'my_tool', description: 'My Tool Description')]
    public function myTool(RequestContext $context): string
    {
        $context->getClientGateway()->log(...);
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

Two more accessors read what the client declared, and both work in either
[protocol era](../protocol-versions.md) — negotiated once during the handshake, or declared per request from
`2026-07-28` on:

```php
$context->getClientCapabilities();  // what this client declared, or null in the handshake era
$context->getTraceContext();        // traceparent / tracestate / baggage, verbatim
```

`ClientGateway`'s capability probes — `supportsElicitation()`, `supportsSampling()`, `supportsRoots()` and the
sub-capability variants — read the same declaration.

W3C trace context is passed through exactly as it arrived, and echoed onto every notification the request causes,
so a span stays joined across the response stream. Reading it adds no OpenTelemetry dependency — the values are
strings.

## Sampling

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. It keeps working on a handshake-era connection until then, but that revision removed server-initiated requests outright, so `sample()` — like `listRoots()` — raises a `LogicException` when a modern-era client made the call. New integrations should call an LLM provider's API directly instead.

With [sampling](https://modelcontextprotocol.io/specification/2025-11-25/client/sampling) servers can request clients to
execute "completions" or "generations" with a language model for them:

```php
$result = $clientGateway->sample('Roses are red, violets are', 350, 90, ['temperature' => 0.5]);
```

The `sample` method accepts four arguments:

1. `message`, which is **required** and accepts a string, an instance of `Content` or an array of `Mcp\Schema\Content\SamplingMessage` instances.
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
$messages[] = new SamplingMessage(Role::Assistant, $result->content);
$messages[] = new SamplingMessage(Role::User, [new ToolResultContent($toolUse->id, [new TextContent($output)])]);
```

The specification is strict about the shape of that exchange: tool results may not be mixed with other content in a
message, and every tool use must be answered before the conversation continues. `sample()` checks these rules before
sending and throws an `InvalidArgumentException` rather than letting the client reject the request with `-32602`.
Use `$result->getContentBlocks()` to iterate the response regardless of whether it holds one block or a list.

[Find more details to sampling payload in the specification.](https://modelcontextprotocol.io/specification/2025-11-25/client/sampling#protocol-messages)

## Logging

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Logging keeps working until then; new integrations should log to stderr (stdio) or use OpenTelemetry instead.

The [Logging](https://modelcontextprotocol.io/specification/2025-06-18/server/utilities/logging) utility enables servers
to send structured log messages as notification to clients:

```php
use Mcp\Schema\Enum\LoggingLevel;

$clientGateway->log(LoggingLevel::Warning, 'The end is near.');
```

## Progress

With a [Progress](https://modelcontextprotocol.io/specification/2025-06-18/basic/utilities/progress#progress)
notification a server can update a client while an operation is ongoing:

```php
$clientGateway->progress(4.2, 10, 'Downloading needed images.');
```

Progress is opt-in by the client in both eras: it sends a `progressToken` with the request, and without one this
call sends nothing.

## Notification

Lastly, the server can push all kind of notifications, that extend the abstract `Mcp\Schema\JsonRpc\Notification` class
to the client to:

```php
$clientGateway->notify($yourNotification);
```
