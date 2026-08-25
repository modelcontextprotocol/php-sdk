# Clients

The client side is for applications that *use* MCP servers: you connect to a server,
discover what it offers, and call it. The API is synchronous — every method returns a
result or throws.

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

* **[Connecting to a server](connecting.md)** — the builder, the connection lifecycle,
  and what the server told you about itself during initialization.
* **[Transports](transports.md)** — launching a local server process (STDIO) or talking
  to a remote one (HTTP).
* **[Tools, resources & prompts](capabilities.md)** — listing and calling everything a
  server exposes, including progress callbacks and completions.
* **[Server-initiated requests](server-requests.md)** — the other direction: log
  messages, sampling requests, and elicitations the server sends *you*.
* **[Error handling](errors.md)** — which exception means what, plus a complete
  end-to-end example.
* **[Clients on this revision](../protocol-versions.md)** — the one builder line that speaks
  protocol revision `2026-07-28`, and what it changes underneath.
