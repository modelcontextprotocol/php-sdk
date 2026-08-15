# Client Communication

MCP supports various ways a server can communicate back to a server on top of the main request-response flow.

## Table of Contents

- [ClientGateway](#client-gateway)
- [Sampling](#sampling)
- [Logging](#logging)
- [Notification](#notification)
- [Progress](#progress)

## ClientGateway

Every communication back to client is handled using the `Mcp\Server\ClientGateway` and its dedicated methods per
operation. To use the `ClientGateway` in your code, you need to use method argument injection for `RequestContext`.

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
        $context->getClientGateway()->log(...);
```

## Sampling

With [sampling](https://modelcontextprotocol.io/specification/2025-11-25/client/sampling) servers can request clients to
execute "completions" or "generations" with a language model for them:

```php
$result = $clientGateway->sample('Roses are red, violets are', 350, 90, ['temperature' => 0.5]);
```

The `sample` method accepts four arguments:

1. `message`, which is **required** and accepts a string, an instance of `Content` or an array of `SamplingMessage` instances.
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

## Notification

Lastly, the server can push all kind of notifications, that implement the `Mcp\Schema\JsonRpc\Notification` interface
to the client to:

```php
$clientGateway->notify($yourNotification);
```
