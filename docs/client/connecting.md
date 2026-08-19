# Connecting to a server

A client is configured once through its builder, then connected to a
[transport](transports.md). Connecting performs the MCP initialization handshake, after
which the server's capabilities are known and its elements can be used. On protocol
revision `2026-07-28` there is no handshake to perform — see
[Clients on this revision](../protocol-versions.md).

## Client Builder

The `Client\Builder` provides fluent configuration of client instances.

### Basic Configuration

```php
use Mcp\Client;

$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0', 'Description of my client')
    ->setInitTimeout(30)      // Seconds to wait for initialization
    ->setRequestTimeout(120)  // Seconds to wait for request responses
    ->setMaxRetries(3)        // Retries for failed connections
    ->build();
```

### Connection Retries

`setMaxRetries()` controls how often `connect()` retries a failed connection. It
counts retries rather than attempts, so the default of `3` means one initial
attempt plus up to three retries — four in total — before the `ConnectionException`
of the last attempt is rethrown:

```php
$client = Client::builder()
    ->setMaxRetries(0)  // Fail on the first failed attempt
    ->build();
```

Between two attempts the transport is closed, so a retry never reuses a
half-established connection: a `StdioTransport` spawns a fresh server process and
an `HttpTransport` discards the session ID of the failed attempt. Each retry is
preceded by a short, linearly growing delay (100ms, 200ms, 300ms, …).

Only the connection handshake is retried. Individual requests such as
`callTool()` are always sent once — retrying them is unsafe as tool calls are not
necessarily idempotent.

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

Specify the MCP protocol version to offer during the handshake (defaults to `V2025_11_25`, the
latest handshake revision — the modern `2026-07-28` revision must be chosen explicitly):

```php
use Mcp\Schema\Enum\ProtocolVersion;

$client = Client::builder()
    ->setProtocolVersion(ProtocolVersion::V2025_11_25)
    ->build();
```

This is an offer, not a demand. A server that does not support the requested revision counter-offers one it does, as
described in the specification's
[protocol version negotiation](https://modelcontextprotocol.io/specification/draft/basic/versioning#protocol-version-negotiation)
section. The client accepts any counter-offer it knows about and continues on that revision; a counter-offer the SDK
cannot speak fails the handshake with a `ConnectionException` rather than continuing on a revision neither side agreed
on. Use `$client->getProtocolVersion()` after connecting to read what was actually negotiated.

Setting a modern revision such as `2026-07-28` selects the other lifecycle rather than making an offer: there is no
`initialize` to negotiate with, so `connect()` sends none and every request carries its own revision instead. Nothing
else about the client API changes. See [Clients on this revision](../protocol-versions.md) for what happens underneath.

See [Protocol versions](../protocol-versions.md#negotiating-in-the-handshake-era) for the server side of the
exchange.

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

Register handlers for server-initiated requests (e.g., sampling). The same handlers answer a
[multi round-trip](../handlers/input-required.md) `input_required` result on a modern revision, where the server
returns its ask instead of sending a request:

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

On a modern revision it opens the transport and asks `server/discover` for the server's identity instead; a server
that does not answer that optional method still yields a usable connection.

!!! warning
    Always wrap connection in try/catch to handle `ConnectionException` for failed connections.

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
