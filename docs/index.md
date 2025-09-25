# MCP PHP SDK

To understand the basics of the Model Context Protocol (MCP), we highly recommend reading the main
[MCP documentation](https://modelcontextprotocol.io) first.

## Building an MCP Server

This SDK provides a way to build an MCP server and wire it up with various capabilities implemented in your application.
Therefore, we differentiate between the core server architecture and its extension points for registering MCP elements.

### Server Setup

The server needs to be configured with some basic information and the discovery of MCP elements. On top, you need to
decide for a transport the server should be connected to, and in the end listen. Which is a long running process in the
end.
For easing the setup of a server, we provide a fluent builder interface:

**ServerBuilder**

```php
use Mcp\Server\ServerBuilder;

$server = ServerBuilder::make()
    ->setServerInfo('My Server', '1.0.0', 'A description of my server.')
    ->setDiscovery(__DIR__, ['.']) // Scan current directory recursively for MCP elements
    ->build();
```
There are more options available on the builder, see [Server Builder documentation](docs/server-builder.md) for details.

**Transport**

The transport is responsible for the communication between the server and the client. The SDK comes with two built-in
transports: `StdioTransport` and `StreamableHttpTransport`.

```php
use Mcp\Server\Transport\StdioTransport;

# STDIO Transport
$transport = new StdioTransport();

# Streamable HTTP Transport
$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);

# Connect the transport to the server
$server->connect(new StdioTransport());

# Start listening (blocking call)
$server->listen();
```

### Implementing MCP Elements

MCP elements are the building blocks of an MCP server. They implement the actual capabilities that can be called by
the client. The SDK comes with a set of attributes and service classes for discovery, that make it handy to register
your code as MCP elements.

```php
final class MyServiceClass
{
    #[McpTool('foo_bar', 'This is my Foo Bar tool.')]
    public function foo(string $bar): string
    {
        return sprintf('Foo %s', $bar);
    }
}
```

More about that can be found in the [Server Builder documentation](docs/server-builder.md).

## Building an MCP Client

Building an MCP Client is currently not supported by this SDK, but part of our roadmap.
