# Server-Initiated Communication

The client can receive requests and notifications from the server when configured with appropriate handlers.

## Logging Notifications

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

## Sampling (LLM Requests)

Handle server requests for LLM completions:

```php
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Exception\SamplingException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;

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

## Elicitation (User Input Requests)

Handle server requests to elicit additional information from the user during tool
execution. The server sends an `elicitation/create` request describing the fields it
needs; your callback presents them to the user and returns an `ElicitResult` with one of
three actions — accept (with the collected content), decline, or cancel:

```php
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Exception\ElicitationException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

class ConsoleElicitationCallback implements ElicitationCallbackInterface
{
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo $request->message.\PHP_EOL;

        // Present $request->requestedSchema->properties to the user and collect input.
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

See `examples/client/stdio_elicitation.php` for a runnable example against the
elicitation demo server.
