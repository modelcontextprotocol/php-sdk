# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.8.0
-----

* Serve both protocol eras from one endpoint: `StreamableHttpTransport` classifies each request — a `2026-07-28` envelope, an `initialize` handshake, or a session-bound follow-up — through the new `Mcp\Server\Wire\InboundClassifier` and routes it to the dispatcher that owns it, so a single URL answers a modern client and a handshake-era one alike. `Server::builder()->build()` now carries both dispatchers; `Builder::withoutModernEra()` opts out and `Builder::setModernVersions()` narrows what the modern leg answers for. `Mcp\Server\InputRequiredShim` lets a handler written for multi round-trip requests also serve a handshake-era client, by turning each ask into the request/response exchange that era has.
* Carry W3C trace context through a request (SEP-414): `traceparent`, `tracestate` and `baggage` in a request's `_meta` are exposed to handlers as `RequestContext::getTraceContext()` and echoed onto the notifications that request causes, so a span stays joined across the response stream. Values pass through exactly as they arrived, and no OpenTelemetry dependency is added.
* Deliver notifications on a `subscriptions/listen` stream (SEP-2575), which previously acknowledged and then carried nothing for the rest of its life. New `Mcp\Server\Subscription\NotificationBusInterface` with two implementations — `InMemoryNotificationBus` for stdio and persistent runtimes, `Psr16NotificationBus` for PHP-FPM, where the worker holding the stream open and the worker publishing are different processes — set with `Builder::setNotificationBus()`. Registry changes are published automatically through a `PublishingEventDispatcher` that wraps whatever PSR-14 dispatcher was configured. `Builder::setSubscriptionLifetime()` replaces the hard-coded 30-second ceiling, where `0` means "until the client or the runtime ends it".
* Add `Mcp\Server\Wire\CachePolicy`, set with `Builder::setCachePolicy()`, to configure the SEP-2549 caching hints the 2026-07-28 lifecycle stamps on a cacheable result. The conservative `ttlMs: 0, cacheScope: private` stays the default, since `public` lets a shared proxy serve one caller's answer to another and only the operator can make that call. A `ReadResourceResult` may also carry its own `ttlMs`/`cacheScope`, which win over the policy.
* Add typed readers to `Mcp\Server\Stateless\InputContext`: `elicitResult()`, `samplingResult()` and `rootsResult()` deserialize a multi round-trip answer instead of handing back the raw array `response()` returns. A malformed answer reads as absent rather than throwing, so a handler asks again — which is what the spec says a server SHOULD do when the information it needs is still missing.
* Answer a request over a response stream under the 2026-07-28 lifecycle: `StatelessProtocol` runs handlers in a fiber, so `$gateway->progress()` and `$gateway->log()` work there as they do in the handshake era. The stream opens only if the handler actually emits something *and* the client's `Accept` admits `text/event-stream`, and the choice is made after the handler's first suspension, so a request that turns out to need `-32021` or `-32602` is still answered with the status the spec fixes for it.
* Honour `io.modelcontextprotocol/logLevel` (SEP-2575), which replaced the `logging/setLevel` RPC: a request naming no level receives no `notifications/message` at all, one naming a level receives the messages at or above it. Adds `LoggingLevel::severity()` and `LoggingLevel::isAtLeast()`.
* [BC Break] Answer a not-found subject with `-32602` (Invalid params) instead of `-32002`, which the 2026-07-28 revision reserves and forbids emitting (SEP-2164). `resources/read` picks the code from the revision serving the request — `-32602` with the uri in `error.data` from `2026-07-28` on, `-32002` below. `prompts/get` for an unknown prompt, `completion/complete` for an unknown reference and `tools/call` for an unknown tool switch to `-32602` in *every* revision: `-32002` was never the code for those. Adds `ProtocolVersion::usesInvalidParamsForResourceNotFound()`.
* Add the multi round-trip requests pattern for the 2026-07-28 lifecycle (SEP-2322): a `tools/call` or `prompts/get` handler returning `Mcp\Schema\Result\InputRequiredResult` comes back as `resultType: "input_required"` carrying the `inputRequests` it needs answered and an opaque `requestState`; the client retries the same request with `inputResponses`, which the handler reads through `RequestContext::getInputContext()`. `Mcp\Server\Stateless\RequestStateCodec` signs and time-bounds the state — set the key with `Builder::setRequestStateKey()`.
* Validate the standard MCP request headers under the 2026-07-28 lifecycle (SEP-2243): `Mcp\Server\Stateless\StandardHeaderValidator`, set with `Builder::setHeaderValidator()`, checks that `Mcp-Method` and `Mcp-Name` agree with the body they travel with and that a `Mcp-Param-*` mirrors the argument its tool marked `x-mcp-header`, answering `-32020` when they disagree. Intermediaries route on these headers, so a value contradicting the body has to be refused rather than ignored.
* [BC Break] `Mcp\Schema\JsonRpc\Error` accepts `null` as its `$id`, and `getId()` may return it. An error response whose id could not be read now omits the member instead of sending `"id": ""` — which claimed the peer had issued a request with an empty-string id. All the `for*()` factories default to `null`, `fromArray()` accepts a missing or explicitly-null id, and `MessageFactory` decodes both as an id-less error rather than rejecting them.
* Preserve the original request `id` on an invalid-but-parseable message (`-32600`) instead of answering it id-less: `InvalidInputMessageException` now carries the recoverable id via `getRequestId()`/`setRequestId()`, threaded from `MessageFactory` through to the error response.
* [BC Break] Add the extensions framework SEP-2133 defines, which MCP Apps sits on. `ExtensionInterface::getId()` now returns the new `Mcp\Schema\Extension\ExtensionIdentifier` value object instead of a string, which validates the identifier against the `_meta` key naming rules at construction time. `ExtensionInterface` also gains `getMessages()`/`getRequestHandlers()`, so an extension can contribute the message classes its methods decode into — without which its methods cannot be decoded at all — and the handlers serving them; extensions that only announce a capability can extend the new `Mcp\Schema\Extension\AbstractExtension` and skip both. `MessageFactory::make()` takes an `$additional` list of message classes, and `RequestHandlerInterface`'s result template is now covariant.
* [BC Break] Drop the SDK-only name pattern on `ResourceDefinition`/`ResourceTemplate` `$name` — the spec allows any string (its own examples use `main.rs` and `Project Files`). URI/URI-template validation is unchanged.
* Add `ClientGateway::supportsExtension()`, `Client\Builder::enableExtension()`, and `ClientCapabilities::withExtensions()` so clients can negotiate and check protocol extensions (e.g. MCP Apps) the same way servers already do. [BC Break] `ServerExtensionInterface` is replaced by the side-agnostic `Mcp\Schema\Extension\ExtensionInterface`.
* Deprecate Roots, Sampling and Logging per SEP-2577 (protocol revision `2026-07-28`, earliest removal `2027-07-28`). They keep working but using them now triggers a deprecation notice — migrate to tool arguments/resource URIs, a direct LLM provider API, and stderr/OpenTelemetry respectively.
* [BC Break] Gate `structuredContent` on the negotiated protocol revision: a tool result that's a PHP list (or an object serializing to a JSON array) is now only sent as `structuredContent` on protocol revisions `2026-07-28`+; older revisions omit it and keep the JSON-encoded value in `content`.
* Add protocol revision negotiation during `initialize`: the server counter-offers a revision it supports, and the client now fails the handshake instead of continuing on an unagreed revision. Adds `Client::getProtocolVersion()` and the `2026-07-28` revision.
* Add sampling-with-tools support: sampling requests can include tools and tool-choice preferences, messages support tool-use/tool-result content blocks, and clients advertise `sampling.context`/`sampling.tools` via `ClientGateway::supportsSamplingTools()`/`supportsSamplingContext()`. A request that violates the spec's tool-flow rules is now rejected with a proper JSON-RPC error instead of being left unanswered.
* [BC Break] `SamplingMessage::$content` and `CreateSamplingMessageResult::$content` may now be a list of content blocks instead of just one — use the new `getContentBlocks()` to always get a list.
* [BC Break] `CreateSamplingMessageResult` now rejects any role other than `assistant`, and rejects empty content, per spec.
* Close the remaining schema gaps for `2025-06-18`/`2025-11-25` and add the `2026-07-28` surface (SEP-2106): url-mode elicitation (`ClientGateway::elicitUrl()`/`supportsElicitationUrl()`), `Implementation::title`, and `outputSchema`/`structuredContent` accepting any JSON value rather than only objects.
* Add `Mcp\Schema\Content\ResourceLink` — reference a resource by URI/name in tool results and prompt messages without embedding its contents.
* Add client-side Roots support: `RootsCallbackInterface`, `Client::sendRootsListChanged()`, and server-side `ClientGateway::listRoots()`/`supportsRoots()`.
* Add `ClientGateway::supportsSampling()` to check the client's advertised capabilities before sending a sampling request, matching `supportsRoots()`/`supportsElicitation()`.
* Fix empty tool/resource schemas serializing as `[]` instead of `{}` in `inputSchema`/`outputSchema`.
* Fix `PromptResultFormatter` dropping `annotations`, `_meta`, and `mimeType` when a prompt generator returns content as a plain array.
* Add `annotations` support to `ImageContent`, matching `TextContent`/`AudioContent`.

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
