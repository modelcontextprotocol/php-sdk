# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.7.0
-----

* Allow `[$instance, 'methodName']` as an element handler in `Builder::addTool()`, `addResource()`, `addResourceTemplate()`, and `addPrompt()`. Unblocks handlers with constructor dependencies that the container-less `new $className()` fallback cannot build.

0.6.0
-----

* Add `Builder::add(Tool|ResourceDefinition|ResourceTemplate|Prompt $definition, ElementHandlerInterface $handler)` for explicit registration of elements whose schema is only known at runtime.
* Add handler interfaces `ToolHandlerInterface`, `ResourceHandlerInterface`, `ResourceTemplateHandlerInterface`, `PromptHandlerInterface`, and the `ElementHandlerInterface` marker.
* [BC Break] Renamed `Mcp\Schema\Resource` to `Mcp\Schema\ResourceDefinition`. No alias.
* [BC Break] Renamed `Mcp\Capability\Registry\Loader\ArrayLoader` to `Mcp\Capability\Registry\Loader\ReflectedElementLoader`.
* [BC Break] Bump default protocol version to `2025-11-25`
* Add support for MCP Apps extension in schema and server
* Add `extensions` to `ServerCapabilities` and `ClientCapabilities` and `Builder::enableExtension()`
* Allow overriding the default name pattern for Discovery
* Add configurable session garbage collection (`gcProbability`/`gcDivisor`)
* Add optional `title` field to `ResourceDefinition` and `ResourceTemplate` for MCP spec compliance
* Add `ChainLoader` to compose multiple `LoaderInterface` implementations via explicit ordering.
* Add `RegistryInterface::unregisterTool()`, `unregisterResource()`, `unregisterResourceTemplate()`, `unregisterPrompt()` — idempotent removals.
* Add `RegistryInterface::hasTool()`, `hasResource()`, `hasResourceTemplate()`, `hasPrompt()` — by-name existence checks.
* `DiscoveryLoader` now refreshes only its own previously written entries; manual registrations (via `Builder::addTool()` etc. or runtime `$registry->registerTool()` calls) survive rediscovery, and a same-name manual registration takes precedence over discovery on collision.
* [BC Break] Removed `ElementReference::$isManual` public property and the `bool $isManual` parameter from all `*Reference` constructors. Origin tracking is no longer carried on the element; manual-over-discovered precedence is encoded by loader execution order.
* [BC Break] `RegistryInterface::registerTool()`, `registerResource()`, `registerResourceTemplate()`, `registerPrompt()` lost their trailing `bool $isManual = false` parameter. Callers using positional arguments must drop the flag.
* [BC Break] Removed `RegistryInterface::clear()`, `getDiscoveryState()`, `setDiscoveryState()`. Rediscovery now goes through `DiscoveryLoader::load()` directly.
* `Registry::register*()` semantics changed to plain last-write-wins (overwrites silently) and the methods now return the stored `*Reference`. The previous "discovered registration is ignored when a manual one already exists" precedence rule still applies, but is now enforced by `DiscoveryLoader` via reference-identity tracking — and still emits a debug log when a discovery is skipped due to a conflicting registration.
* Add optional `title` parameter to `Builder::addResource()` and `Builder::addResourceTemplate()` for MCP spec compliance
* [BC Break] `Builder::addResource()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* [BC Break] `Builder::addResourceTemplate()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* Add `CorsMiddleware`, `DnsRebindingProtectionMiddleware`, and `ProtocolVersionMiddleware` for `StreamableHttpTransport`, composed automatically as the default stack via `StreamableHttpTransport::defaultMiddleware()`
* [BC BREAK] `StreamableHttpTransport` constructor: `$corsHeaders` parameter removed; CORS is now configured via `CorsMiddleware`. The `$middleware` parameter is nullable — `null` (or omitted) installs the default stack; `[]` disables all defaults. Default `Access-Control-Allow-Origin` is no longer set (was `*`).
* [BC Break] `ResourceDefinition::__construct()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* [BC Break] `ResourceTemplate::__construct()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* [BC Break] `McpResource` and `McpResourceTemplate` attribute signatures changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* Harden JSON-RPC input parsing: single-message vs batch is now decided from the decoded JSON type (object → single, list array → batch) instead of the raw first byte. Scalars, empty payloads, and non-object batch elements are surfaced as `InvalidInputMessageException` entries instead of triggering warnings or a `TypeError`.
* Add `maxBatchSize` (default `100`) to `MessageFactory` — oversized JSON-RPC batches are rejected before any message is constructed, guarding against amplification.
* Add `maxBodyBytes` (default 4 MiB) to `StreamableHttpTransport` — POST bodies exceeding the cap are rejected with `413`. Unknown-size/chunked bodies are read incrementally and stopped at the cap so they cannot exhaust memory.

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
* Add optional `title` field to `Tool` and `McpTool` for MCP spec compliance
* [BC Break] `Tool::__construct()` signature changed — `$title` parameter added between `$name` and `$inputSchema`. Callers using positional arguments must switch to named arguments or pass `null` for `$title`.
* [BC Break] `McpTool` attribute signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments for `$description` must switch to named arguments.
* [BC Break] `Builder::addTool()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments for `$description` must switch to named arguments.

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
