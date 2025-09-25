# Server Builder

The `ServerBuilder` is an optional factory class, that makes it easier to create and configure a `Server` instance,
including the registration of MCP elements/capabilities.

## Basic Server Configuration

There is a set of basic server configuration, that is required by the specification, and should always be set:
* Name
* Version
* Description (optional)

This can be set via `setServerInfo`:
```php
$server = ServerBuilder::make()
    ->setServerInfo('My MCP Server', '0.1')
```

## Service Dependencies

All service dependencies are optional and if not set explicitly the builder defaults to skipping them or instantiating
default implementations.

This includes
* Logger, as an instance of `Psr\Log\LoggerInterface`
* Container, as an instance of `Psr\Container\ContainerInterface`
* and more.

```php
$server = ServerBuilder::make()
    ->setLogger($yourLogger)
    ->setContainer($yourContainer)
```

## Register Capabilities

There are two ways of registering MCP elements as capabilities on your server: automated discovery based on file system 
& PHP attributes, and adding them explicitly.

### Discovery

TODO, incl. caching

### Explicit Registration

TODO
