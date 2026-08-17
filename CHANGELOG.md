# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.8.0
-----

* Support for MCP spec version 2026-07-28 incl. stateless HTTP transport
  * Negotiate the protocol revision during `initialize`: the server counter-offers a revision it supports and the client fails the handshake instead of continuing on an unagreed one. Adds `Client::getProtocolVersion()`.
  * Serve both protocol eras from one endpoint: `StreamableHttpTransport` classifies each request (`InboundClassifier`) and routes it to the matching dispatcher, so one URL answers modern and handshake-era clients alike. `Builder::withoutModernEra()` opts out, `Builder::setModernVersions()` narrows the modern leg.
  * Speak the lifecycle from the client: `Client` opens with `server/discover`, stamps `_meta` with version, capabilities and client info, and sends the `Mcp-Method`/`Mcp-Name`/`Mcp-Param-*` headers (via `Client\Stateless\ToolCatalog`). `Schema\Wire\McpHeader` holds the shared header names.
  * Add multi round-trip requests (SEP-2322): a handler returning `InputRequiredResult` yields `resultType: "input_required"` with an opaque, signed `requestState` (`RequestStateCodec`, key via `Builder::setRequestState()`); the client retries with `inputResponses`, read through `RequestContext::getInputContext()` and its typed `elicitResult()`/`samplingResult()`/`rootsResult()`. On the client, `InputRequestResolver` answers such results automatically from the host's elicitation, sampling and roots handlers.
  * Serve `ClientGateway::elicit()`/`elicitUrl()` on every revision: where the client cannot be asked mid-request, `Server\Stateless\ElicitationReplay` turns the ask into `input_required` and resumes once re-sent — the handler is entered once per ask. `InputRequiredShim` does the reverse for handshake-era clients. `sample()`/`listRoots()` raise a `LogicException` on `2026-07-28`, which removed them.
  * Run stateless handlers in a fiber so `$gateway->progress()`/`log()` stream over SSE when the handler emits something and the client accepts `text/event-stream`; honour `io.modelcontextprotocol/logLevel` (SEP-2575). Adds `LoggingLevel::severity()`/`isAtLeast()`.
  * Deliver notifications on `subscriptions/listen` (SEP-2575) via `NotificationBusInterface` — `InMemoryNotificationBus` for persistent runtimes, `Psr16NotificationBus` for PHP-FPM — set with `Builder::setNotificationBus()`; `Builder::setSubscriptionLifetime()` replaces the hard-coded 30s ceiling.
  * Validate the standard request headers (SEP-2243) with `StandardHeaderValidator` (`Builder::setHeaderValidator()`), answering `-32020` when they contradict the body.
  * Add `Wire\CachePolicy` (`Builder::setCachePolicy()`) for SEP-2549 caching hints; defaults to `ttlMs: 0, cacheScope: private`. A `ReadResourceResult` may override with its own values.
  * Carry W3C trace context (SEP-414): `traceparent`/`tracestate`/`baggage` from `_meta` are exposed via `RequestContext::getTraceContext()` and echoed onto the request's notifications.
  * Close the schema gaps for `2025-06-18`/`2025-11-25` and add the `2026-07-28` surface (SEP-2106): url-mode elicitation (`ClientGateway::elicitUrl()`/`supportsElicitationUrl()`), `Implementation::title`, and `outputSchema`/`structuredContent` accepting any JSON value.
  * Deprecate Roots, Sampling and Logging (SEP-2577, earliest removal `2027-07-28`); they keep working but trigger a deprecation notice.
  * [BC Break] Answer a not-found subject with `-32602` instead of `-32002` (SEP-2164): `resources/read` picks the code by revision (`-32602` from `2026-07-28` on), `prompts/get`, `completion/complete` and `tools/call` switch on every revision. Adds `ProtocolVersion::usesInvalidParamsForResourceNotFound()`.
  * [BC Break] A list-shaped tool result is only sent as `structuredContent` on `2026-07-28`+; older revisions keep the JSON-encoded value in `content`.
* Add the Tasks extension (SEP-2663, `io.modelcontextprotocol/tasks`): a server hands back a durable handle instead of holding a connection open — `Mcp\Schema\Task` and `TaskStatus`, `ResultType::Task`, the flat `CreateTaskResult` / `TaskResult` wire shapes, the `tasks/get` / `tasks/update` / `tasks/cancel` surface, and `TaskStoreInterface` with `InMemoryTaskStore` and `Psr16TaskStore` (what PHP-FPM needs). Enable with `Builder::enableExtension(new TasksExtension($store))`; a handler declares a `TaskContext` parameter and creates a task through `TaskContext::create()` after `isSupported()` — a task for a client that did not declare the extension is refused with `-32021`. Advancing a task is the application's job. On the client, `enableExtension(new TasksExtension())` declares it and `Client\Task\TaskClient` speaks it.
* Let an extension reach handler code without the core knowing it: `ArgumentProvidingExtensionInterface` hands handlers objects of the extension's own, injected like a `RequestContext` and left out of the generated schemas; the tool, prompt and resource handlers pass any `ResultInterface` a handler returns through untouched; a `MissingRequiredClientCapabilityException` thrown from handler code is answered as `-32021`; `Client::request()` sends any request.
* [BC Break] Add the extensions framework (SEP-2133) MCP Apps sits on: `ExtensionInterface::getId()` returns an `ExtensionIdentifier` value object, and the interface gains `getMessages()`/`getRequestHandlers()` (extend `AbstractExtension` to skip both). `MessageFactory::make()` takes an `$additional` message list; `RequestHandlerInterface`'s result template is covariant. `ServerExtensionInterface` is replaced by the side-agnostic `Schema\Extension\ExtensionInterface`.
* Add client-side extension negotiation: `ClientGateway::supportsExtension()`, `Client\Builder::enableExtension()`, `ClientCapabilities::withExtensions()`.
* Add sampling-with-tools: sampling requests can carry tools and tool-choice preferences, messages support tool-use/tool-result blocks, and clients advertise `sampling.context`/`sampling.tools` (`ClientGateway::supportsSamplingTools()`/`supportsSamplingContext()`). Requests violating the tool-flow rules are rejected with a JSON-RPC error.
* [BC Break] `SamplingMessage::$content` and `CreateSamplingMessageResult::$content` may be a list of content blocks — use `getContentBlocks()`. `CreateSamplingMessageResult` rejects roles other than `assistant` and empty content.
* Add client-side Roots support (`RootsCallbackInterface`, `Client::sendRootsListChanged()`) and server-side `ClientGateway::listRoots()`/`supportsRoots()`/`supportsSampling()`.
* Add `Schema\Content\ResourceLink` to reference a resource by URI in tool results and prompt messages.
* [BC Break] `Schema\JsonRpc\Error` accepts `null` as `$id`; an unreadable id now omits the member instead of sending `"id": ""`. `MessageFactory` decodes a missing or null id as an id-less error.
* Preserve the request `id` on an invalid-but-parseable message (`-32600`) via `InvalidInputMessageException::getRequestId()`.
* [BC Break] Drop the SDK-only name pattern on `ResourceDefinition`/`ResourceTemplate` `$name`; the spec allows any string.
* Log expected tool failures (`ToolCallException`) at debug level instead of error.
* Add `annotations` to `ImageContent`.
* Fix empty tool/resource schemas serializing as `[]` instead of `{}`.
* Fix `PromptResultFormatter` dropping `annotations`, `_meta` and `mimeType` for plain-array content.

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
