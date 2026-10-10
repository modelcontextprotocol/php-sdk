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

## TimeoutException

Thrown when the server does not answer within the request timeout. It extends
`RequestException`, so catch it first to tell a timeout from an error response:

```php
use Mcp\Exception\RequestException;
use Mcp\Exception\TimeoutException;

try {
    $result = $client->callTool('slow_tool', []);
} catch (TimeoutException $e) {
    echo "The server did not answer in time\n";
} catch (RequestException $e) {
    echo "Request failed: {$e->getMessage()}\n";
}
```

## Complete Example

Here's a comprehensive example demonstrating client usage:

```php-file
<?php

use Mcp\Client;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

// Configure elicitation callback
$elicitationCallback = new class implements ElicitationCallbackInterface {
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo "[ELICITATION] {$request->message}\n";

        // This client has no user interface, so it declines every request
        return new ElicitResult(ElicitAction::Decline);
    }
};

// Build client
$client = Client::builder()
    ->setClientInfo('Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler(new ElicitationRequestHandler($elicitationCallback))
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
