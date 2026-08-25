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
- `maxSseBufferBytes` (int): Maximum buffered bytes for a streamed SSE response

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
