# Error handling

Every exception `Client` throws implements `Mcp\Schema\Exception\ExceptionInterface`. Catch it to handle
client errors in one place. Creating an `HttpTransport` is different: if no PSR-18 client or PSR-17
factory can be found, the constructor throws an exception from `php-http/discovery`. All classes below
live in the `Mcp\Client\Exception` namespace:

| Exception | Thrown when |
| --- | --- |
| `ConnectionException` | `connect()` fails on every attempt, an HTTP request fails on the network level, an HTTP session has expired (status `404`, code `404`), or you call a method on a client that isn't connected |
| `RequestException` | the server answers with an error, the server answers with an HTTP error status other than `401` or `403`, a redirect or a successful body over the size limit, an interrupted event stream can't be resumed, or the STDIO server process exits while you wait for an answer |
| `AuthorizationException` | the server answers a request, a notification or the resumption of an event stream with the HTTP status `401` or `403`, or the [OAuth flow](authorization.md) fails |
| `TimeoutException` | the request timeout (`setRequestTimeout()`, for any method) or the `timeoutSeconds` of a `callTool()` call runs out |
| `RequestCancelledException` | the cancellation token of a `callTool()` call is canceled, see [Cancellation and deadlines](transports.md#cancellation-and-deadlines) |
| `InvalidArgumentException` | `callTool()` gets a `timeoutSeconds` that is not finite and positive, `readResource()` gets an empty URI, `build()` finds an invalid timeout, retry count or fallback revision, the request can't be encoded as JSON (for example invalid UTF-8 in tool arguments), or the server returns a malformed result |
| `RuntimeException` | `callTool()` calls a tool that `listTools()` dropped for malformed `x-mcp-header` annotations, you call `sendRootsListChanged()` without the `rootsListChanged` capability, or, on `2026-07-28`, the server asks for input (elicitation, sampling, roots) that no request handler answers, or whose callback throws |

An HTTP error status or an exited server process fails the request at once. The client doesn't
wait for the request timeout.

## ConnectionException

```php
use Mcp\Client\Exception\ConnectionException;

try {
    $client->connect($transport);
} catch (ConnectionException $e) {
    echo "Failed to connect: {$e->getMessage()}\n";
}
```

A malformed result during `connect()` also throws a `ConnectionException`. In this case, `$e->getPrevious()`
returns the `Mcp\Schema\Exception\InvalidArgumentException`.

## AuthorizationException

This exception also reports a failed [authorization](authorization.md) flow. The message of a refused request contains the `WWW-Authenticate` header of the response, if the server sends one:

```php
use Mcp\Client\Exception\AuthorizationException;

try {
    $result = $client->callTool('get_weather', ['city' => 'Paris']);
} catch (AuthorizationException $e) {
    echo "Not authorized: {$e->getMessage()}\n";
}
```

During `connect()`, the client doesn't retry the request. It throws a `ConnectionException`, and `$e->getPrevious()` returns the `AuthorizationException`.

## RequestException

```php
use Mcp\Client\Exception\RequestException;

try {
    $result = $client->callTool('unknown_tool', []);
} catch (RequestException $e) {
    echo "Request failed: {$e->getMessage()}\n";
    echo "Error code: {$e->getCode()}\n";
}
```

## TimeoutException

Thrown when the request timeout or the `timeoutSeconds` of a `callTool()` call runs out. It extends
`RequestException`, so catch it first to tell a timeout from an error response:

```php
use Mcp\Client\Exception\RequestException;
use Mcp\Client\Exception\TimeoutException;

try {
    $result = $client->callTool('slow_tool', []);
} catch (TimeoutException $e) {
    echo "The server did not answer in time\n";
} catch (RequestException $e) {
    echo "Request failed: {$e->getMessage()}\n";
}
```

## Tool errors

A tool can report a failure in its result. In this case, `callTool()` doesn't throw. It returns a result with
`isError` set to `true`, and the content describes the error:

```php
$result = $client->callTool('get_weather', ['city' => 'Paris']);

if ($result->isError) {
    // show $result->content to the user or the model
}
```

## Complete Example

This script connects to a `server.php` next to it, which provides a `process_data` tool:

```php-file
<?php

require __DIR__.'/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

// Configure elicitation callback
$elicitationCallback = new class implements ElicitationCallbackInterface {
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo "[ELICITATION] {$request->message}\n";

        // This client has no user interface, so it declines every request
        return new ElicitResult(ElicitAction::Decline);
    }
};

// Build client
$client = Client::builder()
    ->setClientInfo('Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler(new ElicitationRequestHandler($elicitationCallback))
    ->build();

// Create transport
$transport = new StdioTransport(
    command: 'php',
    args: [__DIR__.'/server.php'],
);

// Connect and use server
try {
    echo "Connecting to server...\n";
    $client->connect($transport);
    
    // Get server info
    $serverInfo = $client->getServerInfo();
    echo "Connected to: {$serverInfo?->name} v{$serverInfo?->version}\n\n";
    
    // List capabilities
    echo "Available tools:\n";
    $tools = $client->listTools();
    foreach ($tools->tools as $tool) {
        echo "  - {$tool->name}\n";
    }
    
    echo "\nAvailable resources:\n";
    $resources = $client->listResources();
    foreach ($resources->resources as $resource) {
        echo "  - {$resource->uri}\n";
    }
    
    // Call tool with progress
    echo "\nCalling tool with progress...\n";
    $result = $client->callTool(
        name: 'process_data',
        arguments: ['dataset' => 'large_file.csv'],
        onProgress: static function (float $progress, ?float $total, ?string $message) {
            $percent = $total > 0 ? round(($progress / $total) * 100) : 0;
            echo "  Progress: {$percent}% - {$message}\n";
        }
    );
    
    echo $result->isError ? "\nTool error:\n" : "\nResult:\n";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text . "\n";
        }
    }
    
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $client->disconnect();
    echo "\nDisconnected.\n";
}
```
