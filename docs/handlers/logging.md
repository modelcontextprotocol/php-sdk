# Logging

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Logging keeps working until then; new integrations should log to stderr (stdio) or use OpenTelemetry instead.

The SDK provides support to send log messages to clients. All standard PSR-3 log levels are supported.
Level **warning** is the default level, so anything below it is dropped until the client raises the level with
`logging/setLevel`.

!!! note
    Under the [2026-07-28 lifecycle](../lifecycle/requests.md#progress-and-logging) there is no
    `logging/setLevel`: the client names its level in each request's
    `_meta["io.modelcontextprotocol/logLevel"]`, and a request naming none receives no log
    notifications at all.

!!! note
    Only the message is forwarded to the client. A PSR-3 `$context` array is accepted for interface compatibility
    but is **not** sent — interpolate anything you need into the message itself.

## Usage

The SDK automatically injects a `RequestContext` instance into handlers. This can be used to create a `ClientLogger`.

```php
use Mcp\Capability\Logger\ClientLogger;
use Mcp\Server\RequestContext;

#[McpTool]
public function processData(string $input, RequestContext $context): array {
    $logger = $context->getClientLogger();

    $logger->info(\sprintf('Processing started for "%s"', $input));
    $logger->warning('Deprecated API used');
    
    // ... processing logic ...
    
    $logger->info('Processing completed');
    return ['result' => 'processed'];
}
```
