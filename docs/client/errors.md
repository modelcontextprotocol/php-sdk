# Error Handling

The client throws exceptions for various error conditions:

## ConnectionException

Thrown when connection or initialization fails:

```php
use Mcp\Exception\ConnectionException;

try {
    $client->connect($transport);
} catch (ConnectionException $e) {
    echo "Failed to connect: {$e->getMessage()}\n";
}
```

## RequestException

Thrown when a request returns an error response:

```php
use Mcp\Exception\RequestException;

try {
    $result = $client->callTool('unknown_tool', []);
} catch (RequestException $e) {
    echo "Request failed: {$e->getMessage()}\n";
    echo "Error code: {$e->getCode()}\n";
}
```

## Complete Example

Here's a comprehensive example demonstrating client usage:

```php-file
<?php

use Mcp\Client;
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Exception\SamplingException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

// Configure logging notification handler
$loggingHandler = new LoggingNotificationHandler(
    static function (LoggingMessageNotification $notification) {
        echo "[LOG {$notification->level->value}] {$notification->data}\n";
    }
);

// Configure sampling callback
$samplingCallback = new class implements SamplingCallbackInterface {
    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        echo "[SAMPLING] Processing request (max {$request->maxTokens} tokens)\n";
        
        try {
            // Integration with your LLM provider
            $response = "This is a mock LLM response for: " . 
                json_encode($request->messages);
            
            return new CreateSamplingMessageResult(
                role: Role::Assistant,
                content: new TextContent($response),
                model: 'mock-llm',
                stopReason: 'end_turn',
            );
        } catch (\Throwable $e) {
            throw new SamplingException(
                "Sampling failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
};

// Build client
$client = Client::builder()
    ->setClientInfo('Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(sampling: true))
    ->addNotificationHandler($loggingHandler)
    ->addRequestHandler(new SamplingRequestHandler($samplingCallback))
    ->build();

// Create transport
$transport = new StdioTransport(
    command: 'php',
    args: [__DIR__ . '/server.php'],
);

// Connect and use server
try {
    echo "Connecting to server...\n";
    $client->connect($transport);
    
    // Get server info
    $serverInfo = $client->getServerInfo();
    echo "Connected to: {$serverInfo->name} v{$serverInfo->version}\n\n";
    
    // List capabilities
    echo "Available tools:\n";
    $tools = $client->listTools();
    foreach ($tools->tools as $tool) {
        echo "  - {$tool->name}\n";
    }
    
    echo "\nAvailable resources:\n";
    $resources = $client->listResources();
    foreach ($resources->resources as $resource) {
        echo "  - {$resource->uri}\n";
    }
    
    // Set logging level
    $client->setLoggingLevel(LoggingLevel::Debug);
    
    // Call tool with progress
    echo "\nCalling tool with progress...\n";
    $result = $client->callTool(
        name: 'process_data',
        arguments: ['dataset' => 'large_file.csv'],
        onProgress: static function (float $progress, ?float $total, ?string $message) {
            $percent = $total > 0 ? round(($progress / $total) * 100) : 0;
            echo "  Progress: {$percent}% - {$message}\n";
        }
    );
    
    echo "\nResult:\n";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text . "\n";
        }
    }
    
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $client->disconnect();
    echo "\nDisconnected.\n";
}
```
