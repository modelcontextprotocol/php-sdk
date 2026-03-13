# Client

The MCP Client SDK provides a synchronous, framework-agnostic API for communicating with MCP servers from PHP applications.
It handles connection management, request/response correlation, server-initiated requests (sampling), and real-time notifications.

## Table of Contents

- [Overview](#overview)
- [Client Builder](#client-builder)
- [Transports](#transports)
- [Connecting to Servers](#connecting-to-servers)
- [Server Information](#server-information)
- [Working with Tools](#working-with-tools)
- [Working with Resources](#working-with-resources)
- [Working with Prompts](#working-with-prompts)
- [Server-Initiated Communication](#server-initiated-communication)
- [Error Handling](#error-handling)
- [Complete Example](#complete-example)

## Overview

The client follows a builder pattern for configuration and provides a synchronous API for all operations:

```php
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

// Build and configure the client
$client = Client::builder()
    ->setClientInfo('My Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->build();

// Create a transport
$transport = new StdioTransport(
    command: 'php',
    args: ['/path/to/server.php'],
);

// Connect and use the server
$client->connect($transport);
$tools = $client->listTools();
$client->disconnect();
```

## Client Builder

The `Client\Builder` provides fluent configuration of client instances.

### Basic Configuration

```php
use Mcp\Client;

$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0', 'Description of my client')
    ->setInitTimeout(30)      // Seconds to wait for initialization
    ->setRequestTimeout(120)  // Seconds to wait for request responses
    ->setMaxRetries(3)        // Retry attempts for failed connections
    ->build();
```

### Client Information

Set the client's identity reported to servers during initialization:

```php
$client = Client::builder()
    ->setClientInfo(
        name: 'AI Assistant Client',
        version: '2.1.0',
        description: 'Client for automated AI workflows'
    )
    ->build();
```

### Protocol Version

Specify the MCP protocol version (defaults to latest):

```php
use Mcp\Schema\Enum\ProtocolVersion;

$client = Client::builder()
    ->setProtocolVersion(ProtocolVersion::V2025_06_18)
    ->build();
```

### Capabilities

Declare client capabilities to enable server features:

```php
use Mcp\Schema\ClientCapabilities;

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(
        sampling: true,  // Enable LLM sampling requests from server
        roots: true,     // Enable filesystem root listing
    ))
    ->build();
```

### Notification Handlers

Register handlers for server-initiated notifications:

```php
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Schema\Notification\LoggingMessageNotification;

$loggingHandler = new LoggingNotificationHandler(
    static function (LoggingMessageNotification $notification) {
        echo "[{$notification->level->value}] {$notification->data}\n";
    }
);

$client = Client::builder()
    ->addNotificationHandler($loggingHandler)
    ->build();
```

### Request Handlers

Register handlers for server-initiated requests (e.g., sampling):

```php
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

$samplingCallback = new class implements SamplingCallbackInterface {
    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        // Perform LLM sampling and return result
    }
};

$client = Client::builder()
    ->addRequestHandler(new SamplingRequestHandler($samplingCallback))
    ->build();
```

### Logger

Configure PSR-3 logging for debugging:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('mcp-client');
$logger->pushHandler(new StreamHandler('client.log', Logger::DEBUG));

$client = Client::builder()
    ->setLogger($logger)
    ->build();
```

## Transports

Transports handle the communication layer between client and server.

### STDIO Transport

Spawns a server process and communicates via standard input/output:

```php
use Mcp\Client\Transport\StdioTransport;

$transport = new StdioTransport(
    command: 'php',
    args: ['/path/to/server.php'],
    cwd: '/working/directory',     // Optional working directory
    env: ['KEY' => 'value'],       // Optional environment variables
);
```

**Parameters:**
- `command` (string): The command to execute
- `args` (array): Command arguments
- `cwd` (string|null): Working directory for the process
- `env` (array|null): Environment variables
- `logger` (LoggerInterface|null): Optional PSR-3 logger

### HTTP Transport

Communicates with remote MCP servers over HTTP:

```php
use Mcp\Client\Transport\HttpTransport;

$transport = new HttpTransport(
    endpoint: 'http://localhost:8000',
    headers: ['Authorization' => 'Bearer token'],
);
```

**Parameters:**
- `endpoint` (string): The MCP server URL
- `headers` (array): Additional HTTP headers
- `httpClient` (ClientInterface|null): PSR-18 HTTP client (auto-discovered)
- `requestFactory` (RequestFactoryInterface|null): PSR-17 request factory (auto-discovered)
- `streamFactory` (StreamFactoryInterface|null): PSR-17 stream factory (auto-discovered)
- `logger` (LoggerInterface|null): Optional PSR-3 logger

**PSR-18 Auto-Discovery:**

The transport automatically discovers PSR-18 HTTP clients from:
- `php-http/guzzle7-adapter`
- `php-http/curl-client`
- `symfony/http-client`
- And other PSR-18 compatible implementations

```bash
# Install any PSR-18 client - discovery works automatically
composer require php-http/guzzle7-adapter
```


## Connecting to Servers

### Establishing Connection

```php
$client->connect($transport);
```

The `connect()` method performs the MCP initialization handshake:
1. Opens the transport connection
2. Sends InitializeRequest with client capabilities
3. Waits for InitializeResult from server
4. Sends InitializedNotification

> [!IMPORTANT]
> Always wrap connection in try/catch to handle `ConnectionException` for failed connections.

### Checking Connection State

```php
if ($client->isConnected()) {
    // Client is connected and initialized
}
```

### Disconnecting

```php
$client->disconnect();
```

Always disconnect when finished to clean up resources:

```php
try {
    $client->connect($transport);
    // ... use the client ...
} finally {
    $client->disconnect();
}
```

## Server Information

After successful connection, retrieve server metadata:

```php
// Get server implementation info
$serverInfo = $client->getServerInfo();
echo "Server: {$serverInfo->name} v{$serverInfo->version}\n";

// Get server instructions
$instructions = $client->getInstructions();
if ($instructions) {
    echo "Instructions: {$instructions}\n";
}
```

## Working with Tools

### Listing Tools

```php
$toolsResult = $client->listTools();

foreach ($toolsResult->tools as $tool) {
    echo "- {$tool->name}: {$tool->description}\n";
}

// Handle pagination
if ($toolsResult->nextCursor) {
    $moreTools = $client->listTools($toolsResult->nextCursor);
}
```

### Calling Tools

```php
$result = $client->callTool(
    name: 'calculate',
    arguments: ['a' => 5, 'b' => 3, 'operation' => 'add'],
);

// Access results
foreach ($result->content as $content) {
    if ($content instanceof TextContent) {
        echo $content->text;
    }
}
```

### Progress Notifications

Hook into tool execution progress (if server supports it):

```php
$result = $client->callTool(
    name: 'long_running_task',
    arguments: ['data' => 'large_dataset'],
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        $percent = $total > 0 ? round(($progress / $total) * 100) : 0;
        echo "Progress: {$percent}% - {$message}\n";
    }
);
```

> [!NOTE]
> Progress notifications are only received if the server sends them. The callback will not be invoked if the server doesn't support or send progress updates.

## Working with Resources

### Listing Resources

```php
$resourcesResult = $client->listResources();

foreach ($resourcesResult->resources as $resource) {
    echo "- {$resource->uri}: {$resource->name}\n";
}
```

### Listing Resource Templates

```php
$templatesResult = $client->listResourceTemplates();

foreach ($templatesResult->resourceTemplates as $template) {
    echo "- {$template->uriTemplate}: {$template->name}\n";
}
```

### Reading Resources

```php
$resourceResult = $client->readResource('config://app/settings');

foreach ($resourceResult->contents as $content) {
    if ($content instanceof TextResourceContents) {
        echo "Text: {$content->text}\n";
    } elseif ($content instanceof BlobResourceContents) {
        echo "Binary data (base64): {$content->blob}\n";
    }
}
```

Resources also support progress notifications:

```php
$result = $client->readResource(
    uri: 'file://large-file.bin',
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        echo "Reading: {$progress}/{$total} bytes\n";
    }
);
```

## Working with Prompts

### Listing Prompts

```php
$promptsResult = $client->listPrompts();

foreach ($promptsResult->prompts as $prompt) {
    echo "- {$prompt->name}: {$prompt->description}\n";
}
```

### Getting Prompts

```php
$promptResult = $client->getPrompt(
    name: 'code_review',
    arguments: ['language' => 'php', 'code' => '...'],
);

foreach ($promptResult->messages as $message) {
    echo "{$message->role->value}: {$message->content->text}\n";
}
```

Prompts also support progress notifications:

```php
$result = $client->getPrompt(
    name: 'generate_report',
    arguments: ['topic' => 'quarterly_analysis'],
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        echo "Generating: {$message}\n";
    }
);
```

### Requesting Completions

Request auto-completion suggestions for prompt or resource arguments:

```php
use Mcp\Schema\PromptReference;

$completionResult = $client->complete(
    ref: new PromptReference('code_review'),
    argument: ['name' => 'language', 'value' => 'ph'],
);

foreach ($completionResult->values as $value) {
    echo "Suggestion: {$value}\n";
}
```

## Server-Initiated Communication

The client can receive requests and notifications from the server when configured with appropriate handlers.

### Logging Notifications

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

### Sampling (LLM Requests)

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

> [!IMPORTANT]
> **Error Handling in Sampling Callbacks:**
> 
> When implementing sampling callbacks, error handling is critical:
> 
> - **Throw `SamplingException`** to forward specific error messages to the server
> - **Any other exception** will be logged but return a generic error to the server
> 
> This distinction allows you to control what error information the server receives:
> 
> ```php
> // Good: Server receives "Rate limit exceeded" message
> throw new SamplingException('Rate limit exceeded. Retry after 60 seconds.');
> 
> // Bad: Server receives generic "Error while sampling LLM" message
> throw new \RuntimeException('Rate limit exceeded');
> ```

## Error Handling

The client throws exceptions for various error conditions:

### ConnectionException

Thrown when connection or initialization fails:

```php
use Mcp\Exception\ConnectionException;

try {
    $client->connect($transport);
} catch (ConnectionException $e) {
    echo "Failed to connect: {$e->getMessage()}\n";
}
```

### RequestException

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

```php
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
