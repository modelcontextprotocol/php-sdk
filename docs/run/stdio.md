# STDIO Transport

The STDIO transport communicates via standard input/output streams, ideal for command-line tools and MCP client integrations.

```php
$transport = new StdioTransport(
    input: STDIN,           // Input stream (default: STDIN)
    output: STDOUT,         // Output stream (default: STDOUT)
    logger: $logger         // Optional PSR-3 logger
);
```

## Parameters

- **`input`** (optional): Input stream resource. Defaults to `STDIN`.
- **`output`** (optional): Output stream resource. Defaults to `STDOUT`.
- **`logger`** (optional): `LoggerInterface` - PSR-3 logger for debugging. Defaults to `NullLogger`.
- **`runnerControl`** (optional): `RunnerControlInterface` - controls the read loop; the default runs until the input stream closes.
- **`maxLineBytes`** (optional): Maximum accepted line length in bytes. Oversized lines are rejected as invalid messages.

!!! warning
    When using STDIO transport, **never** write to `STDOUT` in your handlers as it's reserved for JSON-RPC communication.
    Use `STDERR` for debugging instead.

## Example Server Script

```php-file
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;

$server = Server::builder()
    ->setServerInfo('STDIO Calculator', '1.0.0')
    ->addTool(function(int $a, int $b): int { return $a + $b; }, 'add_numbers')
    ->addTool(InvokableCalculator::class)
    ->build();

$transport = new StdioTransport();

$status = $server->run($transport);

exit($status); // listen() returns 0 when the input stream closes
```

## Client Configuration

For MCP clients like Claude Desktop:

```json
{
    "mcpServers": {
        "my-php-server": {
            "command": "php",
            "args": ["/absolute/path/to/server.php"]
        }
    }
}
```
