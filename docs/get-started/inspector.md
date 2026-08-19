# Try it with the Inspector

The [MCP Inspector](https://modelcontextprotocol.io/docs/latest/tools/inspector) is an interactive
UI for poking at a server: it lists what the server exposes and lets you call it by
hand. It is the fastest way to see whether your server does what you think it does.

It is a Node.js application, so this needs `npx` on your `PATH`.

## STDIO

Point the Inspector at the command that starts your server — it launches the process
itself:

```bash
npx @modelcontextprotocol/inspector php server.php
```

Open the URL it prints. Under **Tools**, call `add` with `a=1` and `b=2`; you get `3`
back. The form the Inspector built for you — a required integer field for each argument
— came from the type hints on the method. So will the schema every other MCP host sees.

Under **Resources**, read `config://calculator/settings` to get the array back as JSON.

## HTTP

A server behind a web server speaks the [HTTP transport](../run/http.md) instead, so the
last lines of `server.php` change — `StdioTransport` reads stdin and would just block
under `php -S`:

```php title="server.php (HTTP variant)"
use Http\Discovery\Psr17Factory;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

$request = (new Psr17Factory())->createServerRequestFromGlobals();

$response = Server::builder()
    ->setServerInfo('Calculator', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->build()
    ->run(new StreamableHttpTransport($request));

(new SapiEmitter())->emit($response);
```

That needs a PSR-17 implementation and an emitter
(`composer require nyholm/psr7 laminas/laminas-httphandlerrunner`). Start it, then give
the Inspector its URL:

```bash
php -S localhost:8000 server.php
npx @modelcontextprotocol/inspector http://localhost:8000
```

`curl` works too, if you would rather see the wire format:

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","clientInfo":{"name":"test","version":"1.0.0"},"capabilities":{}}}'
```

## Connect a real host

Hosts that launch local servers take the same command the Inspector did. For Claude
Desktop, that is an entry in its configuration file:

```json
{
    "mcpServers": {
        "calculator": {
            "command": "php",
            "args": ["/absolute/path/to/server.php"]
        }
    }
}
```

Use an absolute path: the host does not run the command from your project directory.

## Next

* Add more of what a server can expose: **[Servers](../servers/index.md)**.
* Put it on the web instead of stdin/stdout: **[HTTP transport](../run/http.md)**.
* Drive a server from PHP instead of a UI: **[Clients](../client/index.md)**.
