# Inside your handler

The methods you register are ordinary PHP methods, but they are not cut off from the
protocol. Type-hint a `Mcp\Server\RequestContext` argument anywhere in the signature and
the SDK passes it in — that object is the way back to the client mid-request.

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Elicitation\ElicitationSchema;
use Mcp\Schema\Elicitation\StringSchemaDefinition;
use Mcp\Server\RequestContext;

#[McpTool]
public function bookTable(string $restaurant, RequestContext $context): string
{
    $context->getClientLogger()->info(\sprintf('Booking a table at %s', $restaurant));

    $schema = new ElicitationSchema(['name' => new StringSchemaDefinition('Name for the booking')], ['name']);
    $answer = $context->getClientGateway()->elicit('Who is the booking for?', $schema);

    if (!$answer->isAccepted()) {
        return 'No table booked.';
    }

    return \sprintf('Table at %s booked for %s.', $restaurant, $answer->content['name']);
}
```

Two caveats on this example. The `ClientLogger` drops messages below `warning` until the
client raises the level (see [Logging](logging.md)), so the `info()` message is not sent by
default. And `elicit()` works on both [protocol versions](../protocol-versions.md), but your
handler may run more than once for one call. [Asking for input](input-required.md) explains
how to write it for that.

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
