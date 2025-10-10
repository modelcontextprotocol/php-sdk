# MCP Logging

This document describes how to use the Model Context Protocol (MCP) logging capabilities in the PHP SDK.

## Overview

The MCP logging implementation provides centralized logging capabilities that allow clients to receive and filter log messages from servers. This is particularly useful when working with multiple MCP servers, as it enables unified debugging from a single client interface.

## Key Features

- **Auto-injection**: `McpLogger` is automatically injected into capability handlers
- **Client-controlled filtering**: Clients can set log levels to control message verbosity
- **Centralized logging**: All server logs flow to the client via MCP notifications
- **Fallback support**: Compatible with existing PSR-3 loggers for local debugging
- **Zero configuration**: Works out of the box with minimal setup

## Quick Start

### 1. Enable MCP Logging

```php
use Mcp\Server;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->enableMcpLogging()  // Enable MCP logging capability
    ->build();
```

### 2. Use Auto-injected Logger in Handlers

The `McpLogger` is automatically injected into any capability handler that declares it as a parameter:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\McpLogger;

class MyHandlers
{
    #[McpTool(name: 'process_data')]
    public function processData(array $data, McpLogger $logger): array
    {
        $logger->info('Processing data', ['count' => count($data)]);
        
        try {
            $result = $this->performProcessing($data);
            $logger->debug('Processing completed successfully');
            return $result;
        } catch (\Exception $e) {
            $logger->error('Processing failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
```

## Auto-injection

The MCP SDK automatically injects loggers into capability handlers when you declare them as parameters. This works for:

- **Tools** (`#[McpTool]`)
- **Resources** (`#[McpResource]`)
- **Prompts** (`#[McpPrompt]`)

### Supported Logger Types

You can use either type for auto-injection:

```php
use Mcp\Capability\Logger\McpLogger;
use Psr\Log\LoggerInterface;

// MCP-specific logger (recommended)
public function myTool(string $input, McpLogger $logger): array
{
    $logger->info('Tool called', ['input' => $input]);
    // Logs are sent to client via MCP notifications
    return ['result' => 'processed'];
}

// PSR-3 compatible interface
public function myTool(string $input, LoggerInterface $logger): array
{
    $logger->info('Tool called', ['input' => $input]);
    // Will receive McpLogger instance that implements LoggerInterface
    return ['result' => 'processed'];
}
```

## Log Levels

The implementation supports all RFC-5424 syslog severity levels:

```php
$logger->emergency('System is unusable');
$logger->alert('Action must be taken immediately');
$logger->critical('Critical conditions');
$logger->error('Error conditions');
$logger->warning('Warning conditions');
$logger->notice('Normal but significant condition');
$logger->info('Informational messages');
$logger->debug('Debug-level messages');
```

### Client Log Level Control

Clients can control which log levels they receive by sending a `logging/setLevel` request:

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "logging/setLevel",
    "params": {
        "level": "warning"
    }
}
```

When a log level is set, only messages at that level and higher (more severe) will be sent to the client.

## Advanced Usage

### Fallback Logging

You can provide a fallback PSR-3 logger for local debugging:

```php
use Mcp\Server;
use Psr\Log\LoggerInterface;

$server = Server::builder()
    ->setServerInfo('My Server', '1.0.0')
    ->setLogger($myPsr3Logger)  // Fallback logger for local debugging
    ->enableMcpLogging()        // MCP logging for client notifications
    ->build();
```

With this setup:
- Log messages are sent to the client via MCP notifications
- Log messages are also written to your local PSR-3 logger for server-side debugging

### Custom Container Integration

If you're using a DI container, the MCP logger works seamlessly:

```php
use Mcp\Server;
use Psr\Log\LoggerInterface;

$container = new MyContainer();
$container->set(LoggerInterface::class, $myCustomLogger);

$server = Server::builder()
    ->setContainer($container)
    ->enableMcpLogging()
    ->build();
```

## Examples

### Basic Tool with Logging

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\McpLogger;

#[McpTool(name: 'calculate', description: 'Performs mathematical calculations')]
public function calculate(float $a, float $b, string $operation, McpLogger $logger): array
{
    $logger->info('Calculation requested', [
        'operand_a' => $a,
        'operand_b' => $b,
        'operation' => $operation
    ]);

    switch ($operation) {
        case 'add':
            $result = $a + $b;
            break;
        case 'divide':
            if ($b == 0) {
                $logger->error('Division by zero attempted', ['operand_b' => $b]);
                throw new \InvalidArgumentException('Cannot divide by zero');
            }
            $result = $a / $b;
            break;
        default:
            $logger->warning('Unknown operation requested', ['operation' => $operation]);
            throw new \InvalidArgumentException("Unknown operation: $operation");
    }

    $logger->debug('Calculation completed', ['result' => $result]);
    return ['result' => $result];
}
```

### Resource with Logging

```php
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Logger\McpLogger;

#[McpResource(
    uri: 'config://app/settings',
    name: 'app_config',
    description: 'Application configuration'
)]
public function getConfig(McpLogger $logger): array
{
    $logger->debug('Configuration accessed');
    
    $config = $this->loadConfiguration();
    
    $logger->info('Configuration loaded', [
        'settings_count' => count($config),
        'last_modified' => $config['metadata']['last_modified'] ?? 'unknown'
    ]);
    
    return $config;
}
```

### Error Handling with Logging

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\McpLogger;

#[McpTool(name: 'fetch_data')]
public function fetchData(string $url, McpLogger $logger): array
{
    $logger->info('Starting data fetch', ['url' => $url]);
    
    try {
        $data = $this->httpClient->get($url);
        $logger->debug('HTTP request successful', [
            'url' => $url,
            'response_size' => strlen($data)
        ]);
        
        $parsed = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logger->error('JSON parsing failed', [
                'url' => $url,
                'json_error' => json_last_error_msg()
            ]);
            throw new \RuntimeException('Invalid JSON response');
        }
        
        $logger->info('Data fetch completed successfully', [
            'url' => $url,
            'items_count' => count($parsed)
        ]);
        
        return $parsed;
        
    } catch (\Exception $e) {
        $logger->critical('Data fetch failed', [
            'url' => $url,
            'error' => $e->getMessage(),
            'exception_class' => get_class($e)
        ]);
        throw $e;
    }
}
```

## MCP Notification Format

Log messages are sent to clients as MCP notifications following this format:

```json
{
    "jsonrpc": "2.0",
    "method": "notifications/message",
    "params": {
        "level": "info",
        "data": "Processing completed successfully",
        "logger": "MyService"
    }
}
```

Where:
- `level`: The log level (debug, info, notice, warning, error, critical, alert, emergency)
- `data`: The log message (string or structured data)
- `logger`: Optional logger name for message categorization

## Best Practices

### 1. Use Structured Logging

Include context data with your log messages:

```php
$logger->info('User action performed', [
    'user_id' => $userId,
    'action' => 'file_upload',
    'file_size' => $fileSize,
    'duration_ms' => $duration
]);
```

### 2. Choose Appropriate Log Levels

- **Debug**: Detailed diagnostic information
- **Info**: General operational messages
- **Notice**: Significant but normal events
- **Warning**: Something unexpected happened but the application continues
- **Error**: Error occurred but application can continue
- **Critical**: Critical error that might cause the application to abort
- **Alert**: Action must be taken immediately
- **Emergency**: System is unusable

### 3. Avoid Logging Sensitive Data

Never log passwords, API keys, or personal information:

```php
// ❌ Bad - logs sensitive data
$logger->info('User login', ['password' => $password]);

// ✅ Good - logs safely
$logger->info('User login attempt', ['username' => $username]);
```

### 4. Use Logger Names for Organization

When working with complex applications, use logger names to categorize messages:

```php
public function processPayment(array $data, McpLogger $logger): array
{
    // The logger will include context about which handler generated the log
    $logger->info('Payment processing started', ['amount' => $data['amount']]);
}
```

## Troubleshooting

### Logs Not Appearing in Client

1. **Check if logging is enabled**: Ensure `->enableMcpLogging()` is called
2. **Verify log level**: Client might have set a higher log level threshold
3. **Check transport**: Ensure MCP transport is properly connected

### Auto-injection Not Working

1. **Parameter type**: Ensure parameter is typed as `McpLogger` or `LoggerInterface`
2. **Method signature**: Verify the parameter is in the method signature
3. **Builder configuration**: Confirm `->enableMcpLogging()` is called

### Performance Considerations

- Log messages are sent over the MCP transport, so avoid excessive debug logging in production
- Use appropriate log levels to allow clients to filter noise
- Consider the size of structured data in log messages

## Migration from Existing Loggers

If you're already using PSR-3 loggers, migration is straightforward:

```php
use Mcp\Capability\Attribute\McpTool;
use Psr\Log\LoggerInterface;

// Before: Manual logger injection
class MyService
{
    public function __construct(private LoggerInterface $logger) {}

    #[McpTool(name: 'my_tool')]
    public function myTool(string $input): array
    {
        $this->logger->info('Tool called');
        return ['result' => 'processed'];
    }
}

// After: Auto-injection (remove constructor, add parameter)
class MyService
{
    #[McpTool(name: 'my_tool')]
    public function myTool(string $input, LoggerInterface $logger): array
    {
        $logger->info('Tool called');
        return ['result' => 'processed'];
    }
}
```

The MCP logger implements the PSR-3 `LoggerInterface`, so your existing logging calls will work without changes.