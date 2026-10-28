# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.10.0
------

### Server

* [BC Break] `DocBlockParser::parseDocBlock()` no longer accepts `null`; pass `ReflectionMethod::getDocComment()` as is
* [BC Break] Fix a `DELETE` of an unknown or ended session being answered `200` instead of the `404` the spec requires: `Protocol::destroySession()` and `BaseTransport::handleSessionEnd()` return `false` for such a session, as does the listener of `TransportInterface::onSessionEnd()`. A listener that returns nothing keeps the `200`.
* Fix tools with an array shape parameter whose keys are nullable or unions (e.g. `array{name: string, nickname: string|null}`) rejecting valid arguments with `-32602`: a `null` inside the shape no longer turns the parameter into `array|null`, and such keys get their own types instead of `object`, including lists and nested shapes (`string[]|null`, `array{x: int}|null`).
* Fix `array<string|null>` and other generics with a union element getting `items` of type `object`. A `null` inside a generic no longer makes the parameter nullable, and neither does a DocBlock class whose name contains `null`.
* Fix `Psr16NotificationBus` subscribers missing the notifications published after the bus stayed quiet for longer than its TTL: the sequence counter no longer expires, and a subscriber whose cursor is ahead of a lost counter reads what the bus holds.
* Add `Psr6SessionStore` and `Psr6NotificationBus`, which work like `Psr16SessionStore` and `Psr16NotificationBus` over a PSR-6 cache pool. `Builder::setDiscovery()` also accepts a PSR-6 pool as `$cache`.

### Client

* Fix `Client::connect()` leaving the previous transport open when called again: it closes the current transport first, so no stdio process or HTTP session is left behind.

0.9.0
-----

### TL;DR

* Concurrent requests of one session keep their own answers, session changes and fibers
* The client fails fast instead of waiting out its timeout
* Authorization narrows to the resource server role
* stdio and the client speak both protocol eras

### Server

* Concurrent requests and sessions
  * Add an opt-in per-session lock, `Builder::setSessionLock()`, serializing the concurrent requests of one session so none of them overwrites what another saved. `FileSessionLock` locks with `flock()` next to `FileSessionStore`, whose `gc()` also removes stale lock files; `SymfonyLockSessionLock` uses a symfony/lock store for sessions shared across machines; `SessionLockInterface` takes custom ones. A request that cannot get the lock in time, or hits a failing lock backend, is answered with `503` and a JSON-RPC error (`SessionLockTimeoutException` and `SessionLockException`). `Protocol::__construct()` takes the lock as optional last argument.
  * Fix concurrent requests of one session overwriting each other's session changes: `Session::save()` writes only the keys it changed, onto what the store holds at that point. Writes of a handler after it resumed from an elicitation, sampling request or notification are saved too, and a session deleted while one of its requests still ran no longer comes back when that request saves it.
  * Fix an unreachable `Psr16SessionStore` cache being answered as "session not found" (`404`): the failure is logged and answered with `503`, so clients retry instead of re-initializing. `SessionStoreInterface::exists()` may throw the new `SessionStoreException`.
  * Fix parallel calls of one session getting each other's elicitation answers and progress tokens: each stream polls only the client request its own fiber sent, requests to the client get UUID ids instead of a session counter, and `ClientGateway::progress()` takes the token from its own request.
  * Fix concurrent stdio tool calls that report progress, log, elicit or sample never finishing: `StdioTransport` keeps every suspended fiber and resumes each with the answer to its own request. A call's notifications now go out before its response.
  * [BC Break] Ids of requests to the client are UUIDs: `Protocol::sendRequest()`, `Protocol::checkResponse()` and `Protocol::handleFiberYield()` take and return a `Uuid` instead of an `int`, as does the response finder of a transport; `handleFiberYield()` returns the id of the request it sent. A transport passes the suspended `\Fiber` as last argument to the pending-requests provider and the fiber yield handler, and `StreamableHttpTransport::handleFiberTermination()` takes the terminated `\Fiber` as its first argument.
  * [BC Break] Fix lost responses on concurrent requests of one session over Streamable HTTP: a POST is answered with its own responses instead of taking them from the session's outgoing queue. `TransportInterface::send()` receives every response of a handler that does not suspend, and the outgoing queue only carries server-initiated requests and notifications; a custom transport must deliver each message it gets, as a batch gets several.
  * Fix `StreamableHttpTransport` bundling the session's queued messages (e.g. `notifications/resources/updated`) with a request's response into a JSON array, which clients on `2025-06-18` and later reject: such a request is answered as SSE, the queued messages first. A POST of only responses or notifications is accepted with `202` and no body, and the queued messages stay.
  * Fix requests to the client (elicitation, sampling) staying in the session's pending requests after they timed out or their stream vanished: `Protocol::checkResponse()` expires them with the `Request timed out` error, entries of a vanished stream are dropped a minute past their timeout, and an answer to a request the server is not waiting on is dropped instead of stored.
  * Fix a handler throwing after its fiber resumed escaping to the transport: it is answered with an error response, like one throwing before suspending. A suspended handler is resumed with an error instead of `null` when the transport fails to store what it sends to the client; `ClientGateway::notify()` then throws a `ClientException`.
  * Fix a handshake-era Streamable HTTP stream pinning its PHP worker until the elicitation (or other client request) times out after the client disconnected: the idle stream sends a keep-alive comment and stops once the connection is aborted.
* Authorization and HTTP security
  * [BC Break] Narrow authorization to the resource server role (ADR 0002): remove `OAuthProxyMiddleware`, `ClientRegistrationMiddleware`, `ClientRegistrarInterface`, `ClientRegistrationException`, `JwksProvider`, `JwksProviderInterface`, `OidcDiscoveryInterface` and the OIDC metadata policies; `OidcDiscovery` is internal.
  * [BC Break] Replace `OAuthRequestMetaMiddleware` with `RequestContext::getAccessToken()`, returning the validated `Server\Authorization\AccessToken`; `AuthorizationResult::allow()` takes an `AccessToken` instead of request attributes. `Protocol::processInput()`, `BaseTransport::handleMessage()` and `StreamableHttpTransport::handlePostRequest()` take an `?AccessToken $accessToken` parameter, as does the `TransportInterface::onMessage()` listener as fourth argument.
  * [BC Break] Make `JwtTokenValidator` final with a single issuer instead of a list of issuer aliases, and a `$keys` set (e.g. `CachedKeySet`); `JwtTokenValidator::fromIssuer()` discovers and caches keys in a PSR-6 pool, refetching on unknown key ids. The `alg` allowlist is enforced, `$tokenType` and `$leeway` are added, `requireScopes()` is removed in favour of `ScopePolicy`.
  * [BC Break] Add `ScopePolicy` as third argument of `AuthorizationMiddleware`, answering `403 insufficient_scope` per method and tool, with scope hierarchies; the `resource_metadata` challenge URL comes from the configured resource instead of the `Host` header.
  * [BC Break] `ProtectedResourceMetadata` requires `$resource`, serves at the path derived from it (RFC 9728 §3.1) and requires https except for loopback hosts; drops localized, policy, ToS, extra fields and `$metadataPaths`.
  * Fix OIDC discovery rejecting issuers with a trailing slash (e.g. Authentik, Auth0).
  * Fix CORS preflight requests failing with `401` behind `AuthorizationMiddleware`, which now lets `OPTIONS` requests pass without a token. The default `CorsMiddleware` allows `Mcp-Method`, `Mcp-Name` and `Mcp-Param-*` and exposes `WWW-Authenticate`; an `$allowedHeaders` entry ending in `*` allows each requested header with that prefix.
  * [BC Break] `DnsRebindingProtectionMiddleware` always validates `Host` (a missing one is rejected) and checks `Origin` separately against the new `allowedOrigins`, including scheme and port and empty by default. Same-origin requests are always allowed and both lists accept `['*']`. `allowedOrigins` is the new second constructor argument; pass the factories by name.
  * Add `PassthroughMiddleware` to opt `StreamableHttpTransport` out of its default middleware without the warning an empty `$middleware` list logs.
* Protocol eras
  * [BC Break] Serve both protocol eras over stdio: `StdioTransport` settles the era on the client's first request and serves `2026-07-28` requests, `subscriptions/listen` and `notifications/cancelled` on the one channel. `StatelessAwareTransportInterface` declares `setHandshakeVersions()`, so a server without the modern era names only the revisions it negotiates when refusing a `2026-07-28` request.
  * Answer a bare `initialize` on a `2026-07-28`-only endpoint with `-32022` naming the served revisions, and a request without a session on the handshake leg with its id.
  * Reject a recognized `Mcp-Param-*` header whose mirrored argument is absent from the body with `-32020`, instead of accepting the request (SEP-2243).
  * [BC Break] Announce `listChanged` only where list changes are delivered: never in the `initialize` result, and on `server/discover` only when the registry publishes them to a notification bus. `RegistryInterface` declares `publishesListChanges()`, which `Registry` answers with `true` when its dispatcher is a `PublishingEventDispatcher`; a decorating registry forwards the answer of the one it wraps.
* Tools and validation
  * [BC Break] Validate a tool result's `structuredContent` against the tool's `outputSchema`, which the specification requires the server to honour. A mismatch is answered with a `CallToolResult` carrying `isError: true` instead of the non-conforming value, matching the TypeScript, Python and Java SDKs. Skipped when the tool declares no `outputSchema`, when the result carries no `structuredContent`, and when the result is already an error. Return `new \stdClass()` for an empty object, since `[]` is sent as an array.
  * [BC Break] Add `Builder::setSchemaValidator()` to configure the validator used for `tools/call` input and output, e.g. with a resolver for external `$ref` schemas. `SchemaValidator` takes an optional `Opis\JsonSchema\Validator` as its first constructor argument, moving `$logger` to second (pass `logger:` by name), and `validateAgainstJsonSchema()` no longer validates an empty array as an object (pass `new \stdClass()`).
  * Fix `SchemaValidator` falling back to opis' wording for camelCase keywords like `minLength`, `multipleOf` or `additionalProperties`, and reporting a pointer relative to the `$ref` target inside `$defs`. Clients now see the SDK's messages and the full pointer.
  * [BC Break] Remove the `providerClass` argument of `#[CompletionProvider]`. Use `provider:`, which takes the same class-string and is now the first positional argument.
* Logging, events and runtime
  * [BC Break] Fix the client's log level being ignored: `ClientGateway::log()` drops messages below the level set with `logging/setLevel`, and below `warning` while the client has set none, and `ClientLogger` honours a lower level a `2026-07-28` request asks for with `io.modelcontextprotocol/logLevel`. `ClientLogger` takes only the `ClientGateway`, which applies the level itself.
  * Stop the server `Protocol` from logging full JSON-RPC payloads (tool arguments, client replies) at info level: info records now carry only the method and id, the raw message is logged at debug level.
  * Fix `RequestEvent`, `ResponseEvent` and `ErrorEvent` not being dispatched for `2026-07-28` requests, and `ResponseEvent` and `ErrorEvent` not when a suspended fiber completes.
  * Add `ServerRequestEvent` and `ClientResponseEvent` for handshake-era server-initiated requests (`elicitation/create`, `sampling/createMessage`) and the client's replies.
  * Fix memory growing with every `tools/call` in long-lived processes like FrankenPHP workers or stdio servers.
  * Fix the server's `StdioTransport` answering a request on an idle connection only after ~50ms: it now waits on the input with `stream_select()` instead of sleeping.
  * Fix stateless SSE streams holding back frames until close when PHP output buffering is enabled.

### Client

* Protocol eras
  * [BC Break] Speak both protocol eras by default: the client's default protocol version is `2026-07-28`, and `connect()` probes with `server/discover` and falls back to the `initialize` handshake on `2025-11-25` when the server does not speak the modern era. `Builder::setFallbackProtocolVersion()` picks the fallback revision, or `null` for a modern-only client. Against a server that speaks both, a default client now settles on `2026-07-28`, where a server can no longer sample or list roots through `ClientGateway`; return those asks as an `InputRequiredResult` instead, or call `Builder::setProtocolVersion(ProtocolVersion::V2025_11_25)` on the client.
  * On a `2026-07-28` connection, `Client::setLoggingLevel()` stamps the level on every following request, `Client::ping()` sends `server/discover` and `Client::sendRootsListChanged()` sends nothing.
  * Add a `listen` option to the client's `HttpTransport`, opening the standalone GET stream on which a 2025-era server sends requests and notifications outside of a client request, like `roots/list`. Needs a PSR-18 client that streams response bodies, such as `symfony/http-client`.
* Failing fast and recovering
  * Fail a client request at once instead of waiting out the timeout when the HTTP server refuses it with an error status, or a non-streamed response ends without its answer (truncated, empty, unreadable or not JSON), also when the body of an error status fails to read.
  * Fix the client after the stdio server process exited: requests fail at once with the exit code and the end of stderr, and `Client::isConnected()` turns false.
  * [BC Break] Fix the client request timeout firing up to a second early and throwing a generic `RequestException`: it now throws `TimeoutException` and sends `notifications/cancelled` for the abandoned request. `TimeoutException` extends `RequestException` instead of `Exception`, so a `catch (RequestException)` now also catches timeouts. `ClientStateInterface::getPendingRequests()` reports `timestamp` as a `float` from `microtime(true)` instead of an `int`.
  * Resume an interrupted SSE response in `HttpTransport` with a GET carrying `Last-Event-ID`, honouring the server's `retry` interval. Configure attempts and fallback backoff with `ReconnectPolicy`, the scheduling clock with any PSR-20 `ClockInterface` (default: a monotonic clock). Protocol version `2026-07-28` does not resume streams. A stream that ends without its response and without an event ID now fails the request instead of waiting for its timeout.
  * Fix a client `onProgress` callback receiving progress for an earlier request that timed out: progress now only reaches the callback of the request whose `progressToken` it carries.
  * Fix `Client::getServerInfo()`, `getInstructions()` and `getProtocolVersion()` reporting the previous server's values after a reconnect, whether it failed or reached a `2026-07-28` server whose `server/discover` leaves them out.
  * Fix `Client::connect()` rejecting an empty `serverInfo.version`, as the Python SDK sends, and leaving the transport open when initialization fails on a malformed result: the transport is now closed and the failure wrapped in a `ConnectionException`.
  * Fix the client's `HttpTransport` failing to connect through an amphp-based PSR-18 client, like Symfony's `Psr18Client(new AmpHttpClient())`, where `connect()` failed at the init timeout with `FutureIterator::consume(): Return value must be of type ?array`: requests are now sent from outside the request fiber, where the event loop runs.
* API
  * [BC Break] Add `Client::getServerCapabilities()`, returning what the server declared in `initialize` or, from `2026-07-28` on, in `server/discover`. `ClientStateInterface` declares `setServerCapabilities()` and `getServerCapabilities()`, and its `setProtocolVersion()` and `setServerInfo()` accept `null`, which a custom implementation has to follow.
  * Add `HttpTransport::getSessionId()` to read the server-minted `Mcp-Session-Id`: a request-scoped caller can persist it and pass it back through the constructor's `$headers` on a later transport. Always `null` on `2026-07-28`, which removed protocol-level sessions.
  * Fix `HttpTransport` omitting the `MCP-Protocol-Version` header on requests after the `initialize` handshake, including the closing `DELETE`.
  * Fix `Client::getPrompt()` without arguments sending `"arguments": []`, which servers validating the spec's object type (e.g. the TypeScript SDK) reject: empty arguments are now omitted.
  * Fix the client throwing a native `\JsonException` for request params it cannot encode (e.g. invalid UTF-8): it now throws `InvalidArgumentException`, and a request handler result it cannot encode is answered with an internal error instead of no answer.

### Schema

* [BC Break] Bump `MessageInterface::PROTOCOL_VERSION` to `2026-07-28`. Use `ProtocolVersion::latestHandshake()` where a handshake revision is needed, e.g. in an `initialize` answer.
* Add `Tool::$execution` (`ToolExecution` with a `TaskSupport` enum) and `ServerCapabilities::$tasks`, which were dropped when parsing a 2025-11-25 server's `tools/list` and `initialize` results.
* [BC Break] Reject a `Tool` input schema whose `properties` is not an object or whose `required` is neither a list nor `null`, instead of silently replacing the member. Reject a `completion/complete` whose `argument` is missing `name` or `value`, instead of completing against an empty prefix.
* [BC Break] Add `ElicitationSchema::getDefaults()`, returning the declared `default` of each field to accept a form elicitation with. `AbstractSchemaDefinition` declares an abstract `getDefault()`, which a custom schema definition has to implement.

0.8.1
-----

* Fix variadic tool parameters (e.g. `int ...$scores`) failing with a cast error: each element of the array a client sends is now cast on its own.
* Fix `Builder::build()` with a registry passed to `setRegistry()` publishing one `list_changed` notification per loaded element. Adds `Registry::loadFrom()`.

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

0.7.1
-----

* Cap the line length the server `StdioTransport` reads (`$maxLineBytes`, default 4 MiB) and discard longer lines, so a peer that never sends a newline cannot exhaust memory (GHSA-vm75-qjc7-xh6w).
* Cap the output buffer of the client `StdioTransport` (`$maxBufferSize`, default 4 MiB) and fail the pending request when it is reached (GHSA-6vrr-g4hw-f3hj).
* Cap the SSE buffer of the client `HttpTransport` (`$maxSseBufferBytes`, default 8 MiB) and fail the pending request when it is reached (GHSA-7m52-jw36-44r3).

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
* [BC Break] `StreamableHttpTransport` constructor: `$corsHeaders` parameter removed; CORS is now configured via `CorsMiddleware`. The `$middleware` parameter is nullable — `null` (or omitted) installs the default stack; `[]` disables all defaults. Default `Access-Control-Allow-Origin` is no longer set (was `*`).
* [BC Break] `ResourceDefinition::__construct()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* [BC Break] `ResourceTemplate::__construct()` signature changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.
* [BC Break] `McpResource` and `McpResourceTemplate` attribute signatures changed — `$title` parameter added between `$name` and `$description`. Callers using positional arguments must switch to named arguments.

0.5.0
-----

* Add built-in authentication middleware for HTTP transport using OAuth
* Add client component for building MCP clients
* Add `Builder::setReferenceHandler()` to allow custom `ReferenceHandlerInterface` implementations (e.g. authorization decorators)
* Add elicitation enum schema types per SEP-1330: `TitledEnumSchemaDefinition`, `MultiSelectEnumSchemaDefinition`, `TitledMultiSelectEnumSchemaDefinition`
* [BC Break] Make Symfony Finder component optional. Users would need to install `symfony/finder` now themselves
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
