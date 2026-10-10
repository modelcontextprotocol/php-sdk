# Connecting to a server

A client is configured once through its builder, then connected to a
[transport](transports.md). When you connect, the client and the server agree on a protocol
version. After that, the server's capabilities are known and you can use its tools, resources
and prompts.

## Client Builder

The `Client\Builder` provides fluent configuration of client instances.

### Basic Configuration

```php
use Mcp\Client;

$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0', 'Description of my client')
    ->setInitTimeout(30)      // seconds to wait while connecting (default: 30)
    ->setRequestTimeout(120)  // seconds to wait for each response (default: 120)
    ->setMaxRetries(3)        // retries for a failed connection (default: 3)
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
        description: 'Client for automated AI workflows',
        // optional name to display in user interfaces
        title: 'AI Assistant',
    )
    ->build();
```

### Protocol Version

A client speaks both protocol eras by default. It prefers `2026-07-28`, probes for it with `server/discover` when
connecting, and falls back to the `initialize` handshake on `2025-11-25` when the server turns out not to speak it.
Use `$client->getProtocolVersion()` after connecting to read what the connection settled on.

```php
use Mcp\Schema\Enum\ProtocolVersion;

// Fall back to an older handshake revision instead of 2025-11-25…
$client = Client::builder()
    ->setFallbackProtocolVersion(ProtocolVersion::V2025_06_18)
    ->build();

// …or not at all: connect() then throws a ConnectionException
// when the server doesn't speak 2026-07-28
$client = Client::builder()
    ->setFallbackProtocolVersion(null)
    ->build();
```

Passing a handshake revision to `setProtocolVersion()` skips the probe and opens with `initialize`, the way a client
from before the modern era would:

```php
$client = Client::builder()
    ->setProtocolVersion(ProtocolVersion::V2025_11_25)
    ->build();
```

The handshake is an offer, not a demand. A server that does not support the requested revision counter-offers one it
does, as described in the specification's
[protocol version negotiation](https://modelcontextprotocol.io/specification/latest/basic/versioning#protocol-version-negotiation)
section. The client accepts any counter-offer it knows about and continues on that revision; a counter-offer the SDK
cannot speak fails the handshake with a `ConnectionException` rather than continuing on a revision neither side agreed
on.

See [Protocol versions](../protocol-versions.md#how-the-client-settles-on-an-era) for how the probe is read, and
[the handshake era](../protocol-versions.md#negotiating-in-the-handshake-era) for the server side of the exchange.

### Capabilities

Declare client capabilities to enable server features:

```php
use Mcp\Schema\ClientCapabilities;

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(
        elicitation: true, // Let the server ask the user for input
    ))
    ->build();
```

`ClientCapabilities` also takes sub-capabilities. Pass them as named arguments:

```php
new ClientCapabilities(
    sampling: true,
    samplingContext: true,  // the server may ask to include context in sampling
    samplingTools: true,    // the server may pass tools in sampling requests
    elicitationForm: true,  // form mode, implied by elicitation: true alone
    elicitationUrl: true,   // url mode, the user continues in a browser
);
```

Form mode is implied only when you declare no mode. To support both modes, pass both flags.

To declare a protocol extension (e.g. MCP Apps), call `enableExtension()` on the builder.
See [Protocol extensions](../advanced/extensions.md).

### Notification Handlers

Register handlers for server-initiated notifications:

```php
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Schema\Notification\LoggingMessageNotification;

$loggingHandler = new LoggingNotificationHandler(
    static function (LoggingMessageNotification $notification) {
        $message = \is_string($notification->data) ? $notification->data : json_encode($notification->data);
        echo "[{$notification->level->value}] {$message}\n";
    }
);

$client = Client::builder()
    ->addNotificationHandler($loggingHandler)
    ->build();
```

!!! note
    `LoggingNotificationHandler` is deprecated, together with MCP logging. See
    [Logging notifications](server-requests.md#logging-notifications).

### Request Handlers

Register handlers for server-initiated requests (e.g., elicitation). The same handlers answer a
[multi round-trip](../handlers/input-required.md) `input_required` result on a modern revision, where the server
returns its ask instead of sending a request:

```php
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

$elicitationCallback = new class implements ElicitationCallbackInterface {
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        // Ask the user for the requested input and return their answer.
        // Without a user interface, decline the request:
        return new ElicitResult(ElicitAction::Decline);
    }
};

$client = Client::builder()
    // the server only sends elicitation requests if the client declares the capability
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler(new ElicitationRequestHandler($elicitationCallback))
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

The `connect()` method opens the transport and sends a `server/discover` request. If the server
answers on revision `2026-07-28`, the connection is ready. Otherwise, the client falls back to
the `initialize` handshake on revision `2025-11-25`.

With `setFallbackProtocolVersion(null)`, `connect()` fails instead. See
[How the client settles on an era](../protocol-versions.md#how-the-client-settles-on-an-era).

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
// (null on 2026-07-28 when the server/discover result has no serverInfo)
$serverInfo = $client->getServerInfo();
echo "Server: {$serverInfo?->name} v{$serverInfo?->version}\n";

// Get the capabilities the server declared
if ($client->getServerCapabilities()?->tools) {
    $tools = $client->listTools();
}

// Get server instructions
$instructions = $client->getInstructions();
if ($instructions) {
    echo "Instructions: {$instructions}\n";
}
```
