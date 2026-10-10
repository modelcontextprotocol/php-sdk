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
- `listen` (bool): Open the server's listening stream after the handshake (see below)

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

**Listening for server messages:**

Most of what a server sends belongs to one of the client's requests and arrives on that request's
response. Up to the 2025 revisions, a server may also send requests and notifications that belong to
none of them, like asking for the client's roots, on a standalone GET stream. Pass `listen: true` to
open it:

```php
$transport = new HttpTransport('http://localhost:8000', listen: true);
```

- The stream is read while one of the client's requests is in flight. A message that arrives while
  the client is idle waits for its next request.
- It needs a PSR-18 client that returns before the response body has ended and whose body can be read
  without blocking, such as `symfony/http-client`. A client that buffers the whole body never
  returns from a stream the server keeps open.
- A server without a listening stream answers `405`, and the connection carries on without one.
- On `2026-07-28`, which has no standalone stream, the option does nothing.

## Cancellation and deadlines

`callTool()` accepts optional `cancellation: ?CancellationTokenInterface` and
`timeoutSeconds: ?float` arguments. The token's `isCancellationRequested()`
method must return without blocking. The timeout must be finite and positive
and replaces the default request timeout for this call.
An observed cancellation throws `RequestCancelledException`. When the per-call
or default request timeout runs out, the client throws `TimeoutException`, a
`RequestException`, and sends `notifications/cancelled` for the request.

Interruption is checked before a request goes out and again once the send
returns. The second check is what covers a synchronous `application/json`
answer: the reply is buffered while `send()` is still on the stack, and a call
the caller has given up on must not come back as successful. Its buffered reply
is dropped with the pending request, and the connection stays available for
later calls.

STDIO checks for interruption while polling the server and sends
`notifications/cancelled` for an interrupted pending request. Late responses
are ignored.

HTTP cancellation is cooperative, and what it signals depends on the revision:

- up to `2025-11-25` a disconnect is not a cancellation, so the client sends
  `notifications/cancelled` for the abandoned request. That notification is
  another request on the same connection and can therefore block; it is best
  effort, and a failure to send it is logged rather than reported in place of
  the interruption.
- from `2026-07-28` closing the request's response stream is the signal, so no
  separate notification goes out.

Either way the transport closes the active response body on interruption and
clears the pending request without closing the MCP session. Closing a response
does not guarantee that server-side work stops, and physical socket cleanup
depends on the HTTP client.

PSR-18 requests and PSR-7 body reads can block. Cancellation and deadlines
cannot interrupt those operations, including waiting for headers, reading a
JSON body, or waiting for the next SSE chunk. They take effect only after
control returns to the transport. A per-call deadline is therefore not a hard
HTTP wall-clock limit. Configure network timeouts on the underlying HTTP client
to bound blocking I/O.

This keeps HTTP transport compatible with PSR-18 clients and uses the existing
SSE parser. It requires no framework-specific asynchronous client, at the cost
of delayed cancellation during blocking I/O.
