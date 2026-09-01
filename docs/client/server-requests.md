# Server-Initiated Communication

The client can receive requests and notifications from the server when configured with appropriate handlers.

## Elicitation (User Input Requests)

Handle server requests to elicit additional information from the user during tool
execution. The server sends an `elicitation/create` request describing the fields it
needs; your callback presents them to the user and returns an `ElicitResult` with one of
three actions — accept (with the collected content), decline, or cancel:

```php
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

class ConsoleElicitationCallback implements ElicitationCallbackInterface
{
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo $request->message.\PHP_EOL;

        // In url mode there is no schema to fill in — the user completes the
        // interaction in the browser instead.
        if (ElicitationMode::Url === $request->mode) {
            echo 'Continue in your browser: '.$request->url.\PHP_EOL;

            return new ElicitResult(ElicitAction::Accept);
        }

        // Form mode: present $request->requestedSchema->properties and collect input.
        $content = [];
        foreach ($request->requestedSchema->properties as $name => $definition) {
            $answer = readline($definition->title.': ');

            if (false === $answer) {
                // No input available — let the server know the user cancelled.
                return new ElicitResult(ElicitAction::Cancel);
            }

            $content[$name] = $answer;
        }

        return new ElicitResult(ElicitAction::Accept, $content);
    }
}

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler(new ElicitationRequestHandler(new ConsoleElicitationCallback))
    ->build();
```

Return `new ElicitResult(ElicitAction::Decline)` when the user refuses to provide the
information, and `new ElicitResult(ElicitAction::Cancel)` when they dismiss the request.
Only the `Accept` action carries content.

!!! warning
    **Error Handling in Elicitation Callbacks:**

    - **Throw `ElicitationException`** to forward a specific error message to the server
    - **Any other exception** is logged but returns a generic error to the server

    ```php
    // Good: Server receives "No interactive console available" message
    throw new ElicitationException('No interactive console available');

    // Bad: Server receives generic "Error while processing elicitation" message
    throw new \RuntimeException('No interactive console available');
    ```

See [`examples/client/stdio_elicitation.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_elicitation.php) for a runnable example against the
elicitation demo server.

## Sampling (LLM Requests)

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Sampling keeps working until then; new integrations should call an LLM provider's API directly instead.

Handle server requests for LLM completions:

```php
use Mcp\Client\Exception\SamplingException;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

class LlmSamplingCallback implements SamplingCallbackInterface
{
    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        try {
            // Call your LLM provider
            $response = $this->llmClient->complete(
                messages: $request->messages,
                maxTokens: $request->maxTokens,
                temperature: $request->temperature ?? 0.7,
            );
            
            return new CreateSamplingMessageResult(
                role: Role::Assistant,
                content: new TextContent($response->text),
                model: $response->model,
                stopReason: $response->stopReason,
            );
        } catch (\Throwable $e) {
            // Throw SamplingException to surface error to server
            throw new SamplingException(
                "LLM sampling failed: {$e->getMessage()}",
                (int) $e->getCode(),
                $e
            );
        }
    }
}

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(sampling: true))
    ->addRequestHandler(new SamplingRequestHandler(new LlmSamplingCallback))
    ->build();
```

### Sampling with Tools

Clients that support tool-enabled sampling should advertise that capability and forward the request's `tools` and
`toolChoice` fields to their LLM provider. A provider response that requests tools can be returned as one or more
`ToolUseContent` blocks:

```php
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Result\CreateSamplingMessageResult;

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(
        sampling: true,
        samplingContext: true,
        samplingTools: true,
    ))
    ->addRequestHandler(new SamplingRequestHandler($samplingCallback))
    ->build();

// Inside the sampling callback, after invoking the LLM provider:
return new CreateSamplingMessageResult(
    role: Role::Assistant,
    content: array_map(
        static fn ($call) => new ToolUseContent($call->id, $call->name, $call->input),
        $providerResponse->toolCalls,
    ),
    model: $providerResponse->model,
    stopReason: 'toolUse',
);
```

The server executes the requested tools and sends their results in a later sampling request as `ToolResultContent`
blocks in a user message. The client should pass those blocks back to the LLM provider to continue the sampling loop.

!!! warning
    **Error Handling in Sampling Callbacks:**

    When implementing sampling callbacks, error handling is critical:

    - **Throw `SamplingException`** to forward specific error messages to the server
    - **Any other exception** will be logged but return a generic error to the server

    This distinction allows you to control what error information the server receives:

    ```php
    // Good: Server receives "Rate limit exceeded" message
    throw new SamplingException('Rate limit exceeded. Retry after 60 seconds.');

    // Bad: Server receives generic "Error while sampling LLM" message
    throw new \RuntimeException('Rate limit exceeded');
    ```

## Logging Notifications

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Logging keeps working until then; new integrations should log to stderr (stdio) or use OpenTelemetry instead.

Receive structured log messages from the server:

```php
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Enum\LoggingLevel;

$loggingHandler = new LoggingNotificationHandler(
    static function (LoggingMessageNotification $notification) {
        // Route to your application's logging system
        $level = $notification->level;
        $message = $notification->data;
        
        match ($level) {
            LoggingLevel::Debug => logger()->debug($message),
            LoggingLevel::Info => logger()->info($message),
            LoggingLevel::Warning => logger()->warning($message),
            LoggingLevel::Error => logger()->error($message),
            default => logger()->info($message),
        };
    }
);

$client = Client::builder()
    ->addNotificationHandler($loggingHandler)
    ->build();

// Set minimum log level (optional)
$client->setLoggingLevel(LoggingLevel::Info);
```

## Roots

> **Deprecated** since protocol revision `2026-07-28` ([SEP-2577](https://github.com/modelcontextprotocol/modelcontextprotocol/pull/2577)), earliest removal `2027-07-28`. Roots keep working until then; new integrations should pass directories or files through tool arguments, resource URIs or server configuration instead.

Roots let the client expose a list of `file://` "workspace folders" that the server
is allowed to operate on. Advertise the `roots` capability and register a handler
that answers server `roots/list` requests:

```php
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;

class WorkspaceRootsCallback implements RootsCallbackInterface
{
    public function __invoke(ListRootsRequest $request): ListRootsResult
    {
        return new ListRootsResult([
            new Root('file:///home/user/projects/app', 'Application'),
            new Root('file:///home/user/projects/library', 'Library'),
        ]);
    }
}

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true))
    ->addRequestHandler(new ListRootsRequestHandler(new WorkspaceRootsCallback))
    ->build();
```

When the client's roots change, notify the server so it can request the updated
list via `roots/list`. This requires advertising the `roots.listChanged`
capability (`rootsListChanged: true` above); otherwise `sendRootsListChanged()`
throws a `RuntimeException`. On a client that is not connected it throws a
`ConnectionException`:

```php
$client->sendRootsListChanged();
```

See [`examples/client/stdio_roots.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_roots.php) for a runnable example: it calls the
`inspect_workspace_roots` tool of the client-communication demo server, which
answers by issuing the `roots/list` request back to the client.
