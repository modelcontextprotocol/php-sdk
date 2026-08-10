# Connecting to a server

A client is configured once through its builder, then connected to a
[transport](transports.md). Connecting performs the MCP initialization handshake, after
which the server's capabilities are known and its elements can be used.

## Client Builder

The `Client\Builder` provides fluent configuration of client instances.

### Basic Configuration

```php
use Mcp\Client;

$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0', 'Description of my client')
    ->setInitTimeout(30)      // Seconds to wait for initialization
    ->setRequestTimeout(120)  // Seconds to wait for request responses
    ->build();
```

!!! note
    The builder also exposes `setMaxRetries()`, but the value is currently stored and never acted on — no transport
    retries a failed connection. Do not rely on it.

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
    ->setProtocolVersion(ProtocolVersion::V2025_11_25)
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
