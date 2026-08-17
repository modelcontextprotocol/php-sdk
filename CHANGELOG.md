# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.8.0
-----

* `HttpTransport` now inspects the HTTP status of every response. A non-2xx status whose body is a JSON-RPC error response (404 + `-32601` method-not-found, 400 + `-32020` HeaderMismatch, 400 + UnsupportedProtocolVersionError) is dispatched through the normal message path and surfaces as a `RequestException`, while bodies that cannot be parsed fall back to two new exception classes. `Mcp\Exception\SessionExpiredException` covers a 404 on a request carrying a session id whose body is not a JSON-RPC error (the server has dropped the session; the client clears its session id and marks itself un-initialized), and `Mcp\Exception\HttpTransportException` covers any other non-success status, carrying the status code and a snippet of the body. Both extend `ConnectionException`, so a failing handshake keeps being retried by `Client::connect()`. The transport also sends the negotiated protocol version header on every request, including the `DELETE` that closes the session, and `runRequest()` no longer leaks the active fiber and progress state when a request throws.
* Always emit `{}` for empty tool schemas: `Tool` recursively normalizes every empty sub-schema — `properties`, `items`, `additionalProperties`, `$defs`, combinators and the other draft-07 to 2020-12 schema keywords — in the constructor, for both `inputSchema` and `outputSchema`, so an object position is never serialized as `[]`.
* Prompt generators returning content as typed arrays (`['type' => 'text', ...]` etc.) no longer lose the optional fields: `annotations` on every content type, and `_meta` and an explicit `mimeType` on embedded resource contents, now carry through to the resulting `PromptMessage` instead of being silently dropped. A missing resource `mimeType` still defaults to `text/plain`/`application/octet-stream` as before.
* Add `annotations` support to `ImageContent` (constructor, `fromArray()`, `fromFile()`, `fromString()`, `jsonSerialize()`), matching `TextContent` and `AudioContent`.
* Add client-side `roots/list` handler (`ListRootsRequestHandler` + `RootsCallbackInterface`) and `Client::sendRootsListChanged()`, plus server-side `ClientGateway::listRoots()` / `supportsRoots()` and `ListRootsResult::fromArray()`.
* Add `ClientGateway::supportsSampling()`, so a tool can check the client's advertised capabilities before issuing a `sampling/createMessage` request instead of asking and catching the refusal. Matches the existing `supportsRoots()` and `supportsElicitation()`.
* [BC Break] Gate `structuredContent` on the negotiated protocol revision: `ToolReference::extractStructuredContent()` takes an optional `ProtocolVersion` and, for revisions predating SEP-2106 (`2025-11-25` and earlier, where `structuredContent` must be a JSON object), returns `null` for a tool result that is a PHP list or an object serializing to a JSON array. From `2026-07-28` on both are emitted as-is. Objects serializing to a scalar and arrays holding `Content` instances are never emitted, in any revision. `CallToolHandler` resolves the revision from the request's `_meta` (modern era) or the session (handshake era) and falls back to the strictest rule; it logs a warning when a tool declares an `outputSchema` but returns a value that cannot be sent, and when a self-built `CallToolResult` carries a `structuredContent` the revision does not allow (that one is passed through unchanged). Tools returning a list against an older client keep their JSON-encoded value in `content`; they just no longer advertise an invalid `structuredContent`.
* Add `Mcp\Schema\Content\ResourceLink` for the spec's `resource_link` content block (protocol revision 2025-06-18+), letting tool results and prompt messages reference a resource by URI/name without embedding its contents. Accepted anywhere `resource` (`EmbeddedResource`) content is (de)serialized: `CallToolResult::fromArray()`, `PromptMessage::fromArray()`, and `PromptResultFormatter`.
* Negotiate the protocol revision during the `initialize` handshake: the server echoes a revision it supports and counter-offers `ProtocolVersion::latestHandshake()` otherwise (`Builder::setProtocolVersion()` pins it to exactly one), and the client fails the handshake on a counter-offer it cannot speak rather than continuing on an unagreed revision. Adds `Client::getProtocolVersion()`, the `2026-07-28` revision, and the era helpers on `ProtocolVersion` — revisions from `2026-07-28` on have no `initialize`, so they are excluded from negotiation and from `ProtocolVersionMiddleware`'s default supported set.
* Add sampling with tools support: sampling requests now accept tools and tool-choice preferences, messages support tool-use/tool-result content blocks and multiple content blocks, and clients can advertise the `sampling.context` and `sampling.tools` capabilities. Adds `ClientGateway::supportsSamplingTools()` / `supportsSamplingContext()` to check the sub-capabilities before sending, and `CreateSamplingMessageRequest::validateToolFlow()`, which asserts the spec's tool-flow rules across the whole message list — the client handler rejects a violating request with `-32602` instead of leaving it unanswered, and the gateway refuses to send one.
* [BC Break] `SamplingMessage::$content` and `CreateSamplingMessageResult::$content` may now hold a list of content blocks instead of a single one, so code reading them directly must handle both. Use the new `getContentBlocks()` on either class to always get a list.
* [BC Break] `CreateSamplingMessageResult` now rejects any role other than `assistant`, and rejects empty content, as the specification requires.

0.7.0
-----

* Add client-side elicitation support: `ElicitationCallbackInterface`, `ElicitationRequestHandler`, and `ElicitationException` let clients respond to server elicitation requests.
* Defer element loading to the first registry read: loaders now run at request time (first `has*`/`get*` call) instead of eagerly at `Builder::build()`, fixing empty registries under persistent runtimes (e.g. FrankenPHP worker mode) where a loader's data source is not ready at build time. Adds `Builder::setLazyLoading()` (default on), a public `Registry::load()`, and an optional `LoaderInterface` constructor argument on `Registry`.
* [BC Break] Element loading is lazy by default: loader failures now surface on the first request rather than at `Builder::build()`, and `initialize` advertises capabilities from the configured sources rather than the loaded registry. Call `Builder::setLazyLoading(false)` to restore eager build-time loading.
* Allow `[$instance, 'methodName']` as an element handler in `Builder::addTool()`, `addResource()`, `addResourceTemplate()`, and `addPrompt()`. Unblocks handlers with constructor dependencies that the container-less `new $className()` fallback cannot build.
* Always emit an `items` schema for array tool parameters: untyped arrays get `items: {}` and nullable typed arrays (e.g. `string[]|null`) keep their element type. Fixes strict clients rejecting tools with "array type must have items" (#151).
* Harden JSON-RPC input parsing: single-message vs batch is now decided from the decoded JSON type (object → single, list array → batch) instead of the raw first byte. Scalars, empty payloads, and non-object batch elements are surfaced as `InvalidInputMessageException` entries instead of triggering warnings or a `TypeError`.
* Add `maxBatchSize` (default `100`) to `MessageFactory` — oversized JSON-RPC batches are rejected before any message is constructed, guarding against amplification.
* Add `maxBodyBytes` (default 4 MiB) to `StreamableHttpTransport` — POST bodies exceeding the cap are rejected with `413`. Unknown-size/chunked bodies are read incrementally and stopped at the cap so they cannot exhaust memory.
* Reject malformed `Mcp-Session-Id` headers with a `400` response: a repeated header or a value that is not a valid UUID is now rejected up front instead of surfacing as an uncaught `Uuid::fromString()` error.
* Extract RFC 9728 metadata serving into `ProtectedResourceMetadataHandler`, a transport-neutral PSR-15 `RequestHandlerInterface` that can be mounted directly as a Symfony/Laravel controller; `ProtectedResourceMetadataMiddleware` now delegates to it (no BC break).

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
