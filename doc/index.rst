Model Context Protocol SDK
==========================

The PHP MCP SDK is the low level library that enables communication between a PHP application and an LLM model.

Installation
------------

Install the SDK using Composer:

.. code-block:: terminal

    $ composer require mcp/sdk

Usage
-----

The `Model Context Protocol`_ is built on top of JSON-RPC. There are two types of
messages. A Notification and Request. The Notification is just a status update
that something has happened. There is never a response to a Notification. A Request
is a message that expects a response. There are 3 concepts/capabilities that you
may use. These are::

1. **Resources**: File-like data that can be read by clients (like API responses or file contents)
1. **Tools**: Functions that can be called by the LLM (with user approval)
1. **Prompts**: Pre-written templates that help users accomplish specific tasks

The SDK comes with NotificationHandlers and RequestHandlers which are expected
to be wired up in your application.

JsonRpcHandler
..............

The ``Mcp\Server\JsonRpcHandler`` is the heart of the SDK. It is here
you inject the NotificationHandlers and RequestHandlers. It is recommended to use
the built-in handlers in ``Mcp\Server\NotificationHandlers\*`` and
``Mcp\Server\RequestHandlers\*``.

The ``Mcp\Server\JsonRpcHandler`` is started and kept running by
the ``Mcp\Server``

Transports
..........

The SDK supports multiple transports for sending and receiving messages. The
``Mcp\Server`` is using the transport to fetch a message, then
give it to the ``Mcp\Server\JsonRpcHandler`` and finally send the
response/error back to the transport. The SDK comes with a few transports::

1. **Symfony Console Transport**: Good for testing and for CLI applications
1. **Stream Transport**: It uses Server Side Events (SSE) and HTTP streaming

Capabilities
............

Any client would like to discover the capabilities of the server. Exactly what
the server supports is defined in the ``Mcp\Server\RequestHandler\InitializeHandler``.
When the client connects, it sees the capabilities and will ask the server to list
the tools/resource/prompts etc. When you want to add a new capability, example a
**Tool** that can tell the current time, you need to provide some metadata to the
``Mcp\Server\RequestHandler\ToolListHandler``::

    namespace App;

    use Mcp\Capability\Tool\MetadataInterface;

    class CurrentTimeToolMetadata implements MetadataInterface
    {
        public function getName(): string
        {
            return 'Current time';
        }

        public function getDescription(): string
        {
            return 'Returns the current time in UTC';
        }

        public function getInputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'format' => [
                        'type' => 'string',
                        'description' => 'The format of the time, e.g. "Y-m-d H:i:s"',
                        'default' => 'Y-m-d H:i:s',
                    ],
                ],
                'required' => [],
            ];
        }
    }

We would also need a class to actually execute the tool::

    namespace App;

    use Mcp\Capability\Tool\IdentifierInterface;
    use Mcp\Capability\Tool\ToolCall;
    use Mcp\Capability\Tool\ToolCallResult;
    use Mcp\Capability\Tool\ToolExecutorInterface;

    class CurrentTimeToolExecutor implements ToolExecutorInterface, IdentifierInterface
    {
        public function getName(): string
        {
            return 'Current time';
        }

        public function call(ToolCall $input): ToolCallResult
        {
            $format = $input->arguments['format'] ?? 'Y-m-d H:i:s';

            return new ToolCallResult(
                (new \DateTime('now', new \DateTimeZone('UTC')))->format($format)
            );
        }
    }

If you have multiple tools, you can put them in a ToolChain::

    $tools = new ToolChain([
        new CurrentTimeToolMetadata(),
        new CurrentTimeToolExecutor(),
    ]);

    $jsonRpcHandler = new Mcp\Server\JsonRpcHandler(
        new Mcp\Message\Factory(),
        [
            new ToolCallHandler($tools),
            new ToolListHandler($tools),
            // Other RequestHandlers ...
        ],
        [
            // Other NotificationHandlers ...
        ],
        new NullLogger()
    );

With this metadata and executor, the client can now call the tool.

Extending the SDK
-----------------

If you want to extend the SDK, you can create your own RequestHandlers and NotificationHandlers.
The provided one are very good defaults for most applications but they are not
a requirement.

If you do decide to use them, you get the benefit of having a well-defined interfaces
and value objects to work with. They will assure that you follow the `Model Context Protocol`_.
specification.

You also have the Transport abstraction that allows you to create your own transport
if none of the standard ones fit your needs.

.. _`Model Context Protocol`: https://modelcontextprotocol.io/
