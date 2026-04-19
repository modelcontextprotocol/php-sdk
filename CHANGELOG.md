# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.6.0
-----

* Add optional `title` field to `Tool` and `McpTool` for MCP spec compliance
* [BC Break] `Builder::addTool()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments for `$description` must switch to named arguments.

0.5.0
-----

* Add built-in authentication middleware for HTTP transport using OAuth
* Add client component for building MCP clients
* Add `Builder::setReferenceHandler()` to allow custom `ReferenceHandlerInterface` implementations (e.g. authorization decorators)
* Add elicitation enum schema types per SEP-1330: `TitledEnumSchemaDefinition`, `MultiSelectEnumSchemaDefinition`, `TitledMultiSelectEnumSchemaDefinition`
* [BC break] Make Symfony Finder component optional. Users would need to install `symfony/finder` now themselves
* Add `LenientOidcDiscoveryMetadataPolicy` for identity providers that omit `code_challenge_methods_supported` (e.g. FusionAuth, Microsoft Entra ID)
* Add OAuth 2.0 Dynamic Client Registration middleware (RFC 7591)
* Add optional `title` field to `Prompt` and `McpPrompt` for MCP spec compliance
* [BC Break] `Builder::addPrompt()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments for `$description` must switch to named arguments.

0.4.0
-----

* Rename `Mcp\Server\Session\Psr16StoreSession` to `Mcp\Server\Session\Psr16SessionStore`
* Add missing handlers for resource subscribe/unsubscribe and persist subscriptions via session
* Introduce `SessionManager` to encapsulate session handling (replaces `SessionFactory`) and move garbage collection logic from `Protocol`.

0.3.0
-----

* Add output schema support to MCP tools
* Add validation of the input parameters given to a Tool.
* Rename `Mcp\Capability\Registry\ResourceReference::$schema` to `Mcp\Capability\Registry\ResourceReference::$resource`.
* Introduce `SchemaGeneratorInterface` and `DiscovererInterface` to allow custom schema generation and discovery implementations.
* Remove `DocBlockParser::getSummary()` method, use `DocBlockParser::getDescription()` instead.

0.2.2
-----

* Throw exception when trying to inject parameter with the unsupported names `$_session` or `$_request`.
* `Throwable` objects are passed to log context instead of the exception message.

0.2.1
-----

* Add `RunnerControl` for `StdioTransport` to allow break out from continuously listening for new input.
* Open range of supported Symfony versions to include v5.4

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
