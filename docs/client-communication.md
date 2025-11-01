# Client Communication

MCP supports various ways a server can communicate back to a server on top of the main request-response flow.

## Table of Contents

- [ClientGateway](#client-gateway)
- [Sampling](#sampling)
- [Logging](#logging)
- [Notification](#notification)
- [Progress](#progress)

## ClientGateway

Every communication back to client is handled using the `Mcp\Server\ClientGateway` and its dedicated methods per
operation. To use the `ClientGateway` in your code, there are two ways to do so:

### 1. Method Argument Injection

Every refernce of a MCP element, that translates to an actual method call, can just add an type-hinted argument for the
`ClientGateway` and the SDK will take care to include the gateway in the arguments of the method call:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\ClientGateway;

class MyService
{
    #[McpTool('my_tool', 'My Tool Description')]
    public function myTool(ClientGateway $client): string
    {
        $client->log(...);
```

### 2. Implementing `ClientAwareInterface`

Whenever a service class of an MCP element implements the interface `Mcp\Server\ClientAwareInterface` the `setClient`
method of that class will get called while handling the reference, and in combination with `Mcp\Server\ClientAwareTrait`
this ends up with code like this:

```php
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientAwareTrait;

class MyService implements ClientAwareInterface
{
    use ClientAwareTrait;

    #[McpTool('my_tool', 'My Tool Description')]
    public function myTool(): string
    {
        $this->log(...);
```

## Sampling

With [sampling](https://modelcontextprotocol.io/specification/2025-06-18/client/sampling) servers can request clients to
execute "completions" or "generations" with a language model for them:

```php
$result = $clientGateway->sample('Roses are red, violets are', 350, 90, ['temperature' => 0.5]);
```

The `sample` method accepts four arguments:

1. `message`, which is **required** and accepts a string, an instance of `Content` or an array of `SampleMessage` instances.
2. `maxTokens`, which defaults to `1000`
3. `timeout` in seconds, which defaults to `120`
4. `options` which might include `system_prompt`, `preferences` for model choice, `includeContext`, `temperature`, `stopSequences` and `metadata`

[Find more details to sampling payload in the specification.](https://modelcontextprotocol.io/specification/2025-06-18/client/sampling#protocol-messages)

## Logging

The [Logging](https://modelcontextprotocol.io/specification/2025-06-18/server/utilities/logging) utility enables servers
to send structured log messages as notifcation to clients:

```php
use Mcp\Schema\Enum\LoggingLevel;

$clientGateway->log(LoggingLevel::Warning, 'The end is near.');
```

## Progress

With a [Progress](https://modelcontextprotocol.io/specification/2025-06-18/basic/utilities/progress#progress)
notification a server can update a client while an operation is ongoing:

```php
$clientGateway->progress(4.2, 10, 'Downloading needed images.');
```

## Notification

Lastly, the server can push all kind of notifications, that implement the `Mcp\Schema\JsonRpc\Notification` interface
to the client to:

```php
$clientGateway->notify($yourNotification);
```
