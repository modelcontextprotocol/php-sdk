# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.2.1
-----

* Add `RunnerControl` for `StdioTransport` to allow break out from continuously listening for new input.

0.2.0
-----

* Make `Protocol` stateless by decouple if from `TransportInterface`. Removed `Protocol::getTransport()`.
* Change signature of `Builder::addLoaders(...$loaders)` to `Builder::addLoaders(iterable $loaders)`.
* Removed `ClientAwareInterface` in favor of injecting a `RequestContext` with argument injection.
* The `ClientGateway` cannot be injected with argument injection anymore. Use `RequestContext` instead.
* Removed `ClientAwareTrait`
* Removed `Protocol::getTransport()`
* Added parameter for `TransportInterface` to `Protocol::processInput()`

0.1.0
-----

* First tagged release of package
* Support for implementing MCP server
