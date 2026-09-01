# Inside your handler

The methods you register are ordinary PHP methods, but they are not cut off from the
protocol. Type-hint a `Mcp\Server\RequestContext` argument anywhere in the signature and
the SDK passes it in — that object is the way back to the client mid-request.

```php
use Mcp\Schema\Content\TextContent;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;

#[McpTool]
public function summarize(string $text, RequestContext $context): string
{
    $context->getClientLogger()->info(\sprintf('Summarizing %d characters', \strlen($text)));

    $result = $context->getClientGateway()->sample("Summarize:\n\n".$text, 500);

    // `content` is TextContent|ImageContent|AudioContent (or ToolUseContent
    // blocks when the client samples with tools)
    return $result->content instanceof TextContent ? $result->content->text : '';
}
```

Two caveats on this example: clients drop log messages below `warning` unless they raise
the level first (see [Logging](logging.md)), and `sample()` belongs to the features
[deprecated by revision `2026-07-28`](../protocol-versions.md#deprecations) — on that
revision it throws, and [Asking for input](input-required.md) is the way to write it
instead.

* **[Talking back to the client](client-communication.md)** — the `ClientGateway`:
  asking the client's model for a completion (sampling), reporting progress on a long
  call, and sending notifications.
* **[Logging](logging.md)** — structured PSR-3 log messages that surface in the client,
  not in your server's log file.
* **[Asking for input](input-required.md)** — `ClientGateway::elicit()`, or returning an
  `InputRequiredResult` when a handler needs several answers at once. Either way, one
  handler serves both [protocol eras](../protocol-versions.md).

Handlers that need application services (a database connection, an API client) get them
from the container instead; see
[Service dependencies](../run/server-builder.md#service-dependencies).
