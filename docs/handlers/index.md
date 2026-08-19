# Inside your handler

The methods you register are ordinary PHP methods, but they are not cut off from the
protocol. Type-hint a `Mcp\Server\RequestContext` argument anywhere in the signature and
the SDK passes it in — that object is the way back to the client mid-request.

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Server\RequestContext;

#[McpTool]
public function summarize(string $text, RequestContext $context): string
{
    $context->getClientLogger()->info(\sprintf('Summarizing %d characters', \strlen($text)));

    $result = $context->getClientGateway()->sample("Summarize:\n\n".$text, 500);

    // `content` is TextContent|ImageContent|AudioContent
    return $result->content instanceof TextContent ? $result->content->text : '';
}
```

* **[Talking back to the client](client-communication.md)** — the `ClientGateway`:
  asking the client's model for a completion (sampling), reporting progress on a long
  call, and sending notifications.
* **[Logging](logging.md)** — structured PSR-3 log messages that surface in the client,
  not in your server's log file.
* **[Asking for input](../lifecycle/input-required.md)** — returning an `InputRequiredResult`
  when a handler needs elicitation, sampling or roots. Written that way, one handler serves
  both protocol eras.

Handlers that need application services (a database connection, an API client) get them
from the container instead; see
[Service dependencies](../run/server-builder.md#service-dependencies).
