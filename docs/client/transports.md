# Transports

Transports handle the communication layer between client and server.

## STDIO Transport

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
- `maxBufferSize` (int): Maximum buffered bytes per message before the transport gives up

## HTTP Transport

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
- `maxSseBufferBytes` (int): Maximum bytes buffered for one incomplete SSE event (default: 8 MiB)
- `maxReconnectAttempts` (int): Maximum GET attempts to resume an interrupted SSE stream (default: 5)
- `initialReconnectDelayMs` (int): Initial fallback delay when the server sends no `retry` field (default: 1000 ms)
- `maxReconnectDelayMs` (int): Cap for the fallback exponential backoff (default: 10000 ms)
- `clock` (callable|null): Optional monotonic millisecond clock, primarily for deterministic tests

When an SSE connection closes before its JSON-RPC response arrives, the transport resumes it with a GET request carrying the latest `Last-Event-ID`. A server-provided SSE `retry` value controls the delay; otherwise the fallback delay doubles from 1 second up to 10 seconds. Completed requests and explicitly closed transports are not reconnected.

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
