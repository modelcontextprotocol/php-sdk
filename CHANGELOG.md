# Changelog

All notable changes to `mcp/sdk` will be documented in this file.

0.11.0
------

### Server

* Discovery
  * [BC Break] Generate tool input and output schemas with `symfony/json-schema`. Unions are described with `anyOf`, `null` comes last in `type`, `default: null` is no longer emitted, array shapes are closed with `additionalProperties: false`, string-keyed maps get `additionalProperties`, and a class parameter is described by its properties.
  * [BC Break] `SchemaGenerator` takes a `Symfony\Component\JsonSchema\SchemaGeneratorInterface`, a `TypeResolverInterface` and the input and output `Configuration` instead of a `DocBlockParser`.
  * [BC Break] Describe `array<int, V>` and `array<array-key, V>` as a JSON array, so a JSON object is rejected. Use `array<string, V>` for a map.
  * [BC Break] Remove `DocBlockParser::getParamTypeString()` and `DocBlockParser::getParamDescription()`.
  * Add the `outputSchemaFromReturnType` argument of `SchemaGenerator`, to describe the output of a tool that returns an object by its class.

0.10.0
------

### TL;DR

* The SDK is split into the `Mcp\Schema`, `Mcp\Client` and `Mcp\Server` namespaces, ahead of separate packages: most imports change
* PHP 8.2 or higher is required, and JSON-RPC batches are gone
* The client authorizes with OAuth: authorization code, client credentials, `private_key_jwt` and experimental cross-app access
* Handshake-era servers over HTTP run without sessions unless `Builder::setSession()` is called, and sessions belong to the principal that opened them
* Request handlers can run under an event loop (Revolt, ReactPHP) with the opt-in `Builder::setFiberScheduler()`, and stream their responses
* Tool arguments keep their JSON types, and arguments that don't fit are answered with `-32602`
* stdio works on Windows, in the server and the client

### Namespaces

* [BC Break] Split the root namespace into `Mcp\Schema`, `Mcp\Client` and `Mcp\Server` to prepare slicing them into separate packages; update your imports. `Mcp\Schema` depends on nothing, `Mcp\Client` and `Mcp\Server` depend on `Mcp\Schema` only — enforced by Deptrac (`make deptrac`).
  * `Mcp\Server` => `Mcp\Server\Server`
  * `Mcp\Client` => `Mcp\Client\Client`
  * `Mcp\Capability\*` => `Mcp\Server\Capability\*` (`Attribute`, `Completion`, `Discovery`, `Formatter`, `Logger`, `Registry`, `Tool`, plus `Registry` and `RegistryInterface`)
  * `Mcp\Event\*` => `Mcp\Server\Event\*`
  * `Mcp\JsonRpc\MessageFactory` => `Mcp\Schema\MessageFactory`
  * `Mcp\Server\Stateless\RequestMeta` => `Mcp\Schema\RequestMeta`
* [BC Break] Distribute `Mcp\Exception\*` across the three namespaces.
  * `Mcp\Exception\{ExceptionInterface,InvalidArgumentException,LogicException,RuntimeException}` => the same names under `Mcp\Schema\Exception\`, `Mcp\Client\Exception\` and `Mcp\Server\Exception\` — pick the one matching the package you catch around
  * `Mcp\Exception\Exception` => `Mcp\Client\Exception\Exception` and `Mcp\Server\Exception\Exception`
  * `Mcp\Exception\{InvalidInputMessageException,MissingRequestMetaException}` => `Mcp\Schema\Exception\*`
  * `Mcp\Exception\{ConnectionException,ElicitationException,RequestCancelledException,RequestException,RootsException,SamplingException,TimeoutException}` => `Mcp\Client\Exception\*`
  * `Mcp\Exception\{BadMethodCallException,ClientException,ConfigurationException,ContainerException,InvalidCursorException,MissingRequiredClientCapabilityException,NotFoundExceptionInterface,PromptGetException,PromptNotFoundException,RegistryException,RequestStateException,ResourceNotFoundException,ResourceReadException,ServiceNotFoundException,SessionLockException,SessionLockTimeoutException,SessionStoreException,ToolCallException,ToolNotFoundException}` => `Mcp\Server\Exception\*`
  * `Mcp\Exception\HandlerNotFoundException` is removed; it was unused.
  * `Mcp\Server\Exception\ExceptionInterface` and `Mcp\Client\Exception\ExceptionInterface` extend `Mcp\Schema\Exception\ExceptionInterface`, so catching the latter still catches any SDK exception.
  * The named exceptions extend their package's `RuntimeException`/`InvalidArgumentException` instead of the global ones, so `catch (Mcp\Server\Exception\RuntimeException)` reaches `ToolCallException`, `PromptGetException` and friends.
* [BC Break] Move `ExtensionInterface::getRequestHandlers()` to the new `Mcp\Server\Extension\ServerExtensionInterface`, so `Mcp\Schema` does not reference `Mcp\Server`. `AbstractExtension` no longer defines it. An extension that serves its methods must implement the new interface, or `Builder::enableExtension()` does not register its handlers.

### General

* [BC Break] Require PHP 8.2 or higher, as PHP 8.1 reached its end of life.
* [BC Break] Remove JSON-RPC batch support, as MCP dropped batching in `2025-06-18`. The server refuses a JSON array with `-32600`, and HTTP status `400`, on every revision, including `2025-03-26`, which requires batches and which the server still negotiates. The client drops one with a warning.
* [BC Break] Refuse OAuth URLs, in the server and the client, whose host is `0.0.0.0`, `::`, an IPv4 address within IPv6 or an IPv4 address in a non-standard form (e.g. `127.1`), or that hold percent-encoding or non-ASCII characters (give an internationalized name in punycode). In the server, this applies to the issuer, the `jwks_uri` and the resource.
* [BC Break] Require `phpdocumentor/reflection-docblock` `^6.0`, with `phpdocumentor/type-resolver` `^2.0` and `phpstan/phpdoc-parser` `^2.0`: older versions cannot parse types like `non-empty-array<T>`, and the SDK silently ignored such a `@param`. Require `psr/container` `^1.1`, as `Container` is not compatible with `1.0`.
* `ext-fileinfo` is optional: without it, creating content from a file without a MIME type throws a `LogicException`.

### Server

* Sessions
  * [BC Break] Serve handshake-era clients over Streamable HTTP without sessions unless `Builder::setSession()` is called, instead of falling back to an `InMemorySessionStore` that PHP-FPM and `php -S` lose between requests. `initialize` is answered without `Mcp-Session-Id`, later requests run on an `EphemeralSessionInterface` that is never stored, and `GET` and `DELETE` get `405`, so a scaled-out server needs no shared session store or lock. The protocol version comes from the `MCP-Protocol-Version` header. Stdio and the `2026-07-28` era are not affected. Without a session, requests to the client (elicitation, sampling, roots), `Protocol::sendRequest()`, `Protocol::sendNotification()` and saving the session throw a `SessionRequiredException`, answered with `-32603` if a handler does not catch it. Input-required results (unless `withoutInputRequiredShim()` is used), `resources/subscribe`, `resources/unsubscribe` and `logging/setLevel` get `-32603`, `initialize` offers neither resource subscriptions nor logging, log messages are dropped and the client capability checks of `ClientGateway` return `false`. Keep sessions with e.g. `setSession(new InMemorySessionStore())` in a long-running process.
  * [BC Break] Bind handshake-era sessions to the principal that opened them: `AccessToken::getPrincipal()`, or the `StreamableHttpTransport::PRINCIPAL_ATTRIBUTE` request attribute when set. A request of another principal, or to a session opened before the upgrade, gets `404`. `Protocol::processInput()`, the transports' `handleMessage()`, `handleSessionEnd()`, `handlePostRequest()` and `handleDeleteRequest()`, and the `onMessage()` and `onSessionEnd()` listeners take a `?string $principal` as last argument.
  * [BC Break] Add `SessionInterface::isUnreadable()`, which a custom session implements. A session the store holds without attributes, because a handler cleared it or its data is corrupt, gets `404` so the client opens a new one; only a session the store fails to return gets `503`. `FileSessionStore::exists()` no longer reports a zero-byte file.
  * [BC Break] Answer a `DELETE` of an unknown or ended session with `404` instead of `200`, as the spec requires: `Protocol::destroySession()`, `BaseTransport::handleSessionEnd()` and the listener of `TransportInterface::onSessionEnd()` return `false` for it. A listener that returns nothing keeps the `200`.
  * [BC Break] Answer malformed JSON and an `initialize` sent with a session id with HTTP `400`, and a failure before the session is checked with `500`, instead of `200`. Such an answer no longer carries, or removes, the messages queued for the session in `Mcp-Session-Id`.
  * Add `Psr6SessionStore` and `Psr6NotificationBus`, the PSR-6 counterparts of `Psr16SessionStore` and `Psr16NotificationBus`. `Builder::setDiscovery()` also accepts a PSR-6 pool as `$cache`.
  * Fix `Session::save()` wiping the other keys of a session the store failed to read, e.g. on a `Psr16SessionStore` cache error: it returns `false` instead.
  * Fix `FileSessionStore` serving a partial session on Windows when a write could not replace the session file: it no longer falls back to `copy()`, retries the move for up to a second while another process holds the file, and `write()` returns `false` if the move fails.
  * Fix `InMemorySessionStore::exists()` reporting a session past its TTL as existing.
  * Fix `Psr16NotificationBus` subscribers missing notifications published after the bus stayed quiet for longer than its TTL.
* Authorization and HTTP security
  * [BC Break] Answer `503` with `Retry-After` and without a Bearer challenge when `JwtTokenValidator` cannot fetch the signing keys or reaches the limit of the new `RateLimitedJwksClient`, which replaces the `rateLimit` of `CachedKeySet`. `CorsMiddleware` exposes `Retry-After` by default.
  * [BC Break] `AuthorizationMiddleware` answers a body over its `maxBodyBytes` with `413` and a JSON-RPC error, like the transports, instead of `400` with a `WWW-Authenticate` challenge, and logs a warning to its new `$logger`.
  * [BC Break] `ScopePolicy::requiredFor()` takes one decoded message, renames its parameter from `$payload` to `$message` and returns only the default scopes for a list.
  * Fix `JwtTokenValidator` throwing a `TypeError`, or answering `503`, on a token naming a JWKS key whose type or curve `firebase/php-jwt` does not support (e.g. `AKP`, `P-521` since its v7.1, X25519): the token is rejected with `401`.
  * Fix `SecureUrl` rejecting plain http on `*.localhost` hosts (RFC 6761) and IPv4 loopback addresses other than `127.0.0.1`, which broke local setups like `http://shop.localhost:8080`. Plain http is accepted for `localhost`, `*.localhost`, `127.0.0.0/8` and `::1`; every other host still requires https.
  * Fix `SecureUrl` accepting a URL with a backslash, whitespace or control character, so `http://evil.example\@localhost` passed as loopback although browsers read `evil.example` as the host.
* Tools, arguments and results
  * [BC Break] Keep the JSON types of `tools/call` arguments, so validation sees what the client sent: `{}` and `{"0":"x"}` are objects, `[]` and `["x"]` are arrays. Nested objects in `CallToolRequest::$arguments` and in the arguments of `ToolHandlerInterface::execute()` are `\stdClass`, as they are for parameters without a type or typed `mixed`, `object` or `\stdClass`; parameters typed `array` still receive arrays at every level. `array<string, T>` parameters generate `{"type": "object"}`, and `array<int, T>` parameters accept a JSON array or object.
  * [BC Break] Reject `tools/call` whose `arguments` is not a JSON object, including `[]`, with `-32602`, and one whose message has a member name starting with a NUL byte with `-32600`.
  * [BC Break] Answer params that do not fit a known method (e.g. `tools/call` without `name`) with `-32602` instead of `-32600`, as JSON-RPC requires. `Request::fromArray()` throws the new `InvalidParamsException`, a subclass of `InvalidArgumentException`, and `InvalidInputMessageException::isInvalidParams()` tells the protocols which code to send.
  * [BC Break] Answer an argument that cannot be cast to its parameter type with `-32602` instead of `-32603`, for tools, prompts and resources. The message names the parameter. A `string` parameter refuses a list or an object instead of receiving `"Array"`, and an int-backed enum refuses a value with a fraction instead of truncating it. A missing required argument of a prompt, or of a tool that gets past schema validation, gets `-32602` too.
  * [BC Break] Modern tools with an `outputSchema` send and validate a null result as structured content, with JSON text `null` alongside it. A tool whose schema does not allow null, including a `void` tool, returns a tool error instead of the text `(null)`. `ToolReference::extractStructuredContent()` returns `StructuredContent::Absent` when there is nothing to send.
  * [BC Break] `ReferenceHandler` resolves a string handler that is not an invokable class from the container by service id. The container goes first, so a function handler no longer runs if the container has an entry of that name.
  * Fix an `int` parameter receiving a wrong value: a number beyond the integer range wrapped around, also past the schema's `minimum` or `maximum`, a numeric string beyond it became `PHP_INT_MAX` or `PHP_INT_MIN`, and `"--5"` became `0`. They are refused now.
  * Fix a nullable parameter with a default (e.g. `?string $category = 'books'`) receiving the default when the client sends `null`.
  * Fix DocBlock types producing wrong schemas, so valid arguments were rejected with `-32602`: PHPStan/Psalm pseudo-types and literal types (`non-empty-string`, `positive-int`, `'asc'|'desc'`, `array<array-key, T>`) get the JSON Schema type of their base type, nullable and union keys of an array shape (`array{nickname: string|null}`) and union elements of a generic (`array<string|null>`) get their own types instead of `object`, and a `null` inside them, or in a class name, no longer makes the parameter nullable.
  * Fix tool name validation to follow the spec (1 to 128 characters of `A-Z`, `a-z`, `0-9`, `_`, `-` and `.`): names of 65 to 128 characters no longer log a warning, names with a `/` or a trailing newline do.
  * Fix `content`, `messages` and `contents` being sent as a JSON object when the handler returned a non-list array, e.g. from `array_filter()`, and named keys in nested mixed arrays overwriting each other.
  * Fix a scalar `structuredContent` on `2026-07-28` going out as raw text in `content`: `'sunny'` is sent as the JSON `"sunny"`, as SEP-2106 requires.
  * Fix a resource whose text is not valid UTF-8 failing `resources/read`: `ResourceResultFormatter` sends it as a blob with the same MIME type.
  * Encode arrays and objects in results and `TextContent` as compact JSON instead of pretty-printed JSON.
  * Fix `EnumCompletionProvider` and `ListCompletionProvider` offering all values for the prefix `"0"`.
  * Log the name of a called tool at info level; its arguments stay at debug level.
* Discovery and registration
  * [BC Break] `Builder::build()` and `Builder::buildStateless()` throw a `RuntimeException` when `setDiscovery()` is used without `symfony/finder` and without a custom discoverer, instead of logging a warning and discovering nothing.
  * [BC Break] `DocBlockParser::parseDocBlock()` no longer accepts `null`; pass `ReflectionMethod::getDocComment()` as is.
  * Fix `CachedDiscoverer` returning the elements of a discovery that only differs in its name patterns, and `clearCache()` wiping the whole cache instead of its own entries.
  * Fix discovery skipping a class whose file declares an enum before it, once that file has been loaded.
  * Fix a manually registered element with a bad handler answering every list and call with `-32602` and the internal message: the client gets `-32603` and the `ConfigurationException` is logged.
* Protocol `2026-07-28`
  * [BC Break] Answer `server/discover` with `DiscoverHandler`, which `Builder::addRequestHandler()` can replace, and dispatch `RequestEvent`, `ResponseEvent` and `ErrorEvent` for it. A `StatelessProtocol` built without the `Builder` needs `DiscoverHandler` and a `MessageFactory` with `DiscoverRequest`.
  * Add a `binder` argument to `Builder::setRequestState()` that binds `requestState` to the request it was minted for, as the spec recommends: a state echoed on a request that binds to another value is refused like a forged one. `OriginatingRequestBinder` binds the principal, the method, the tool, prompt or resource and a digest of the arguments; A token without a subject fails closed, and `RequestStateBinderInterface` takes custom bindings. Without a binder, nothing changes.
  * Rotate the `requestState` signing key without failing calls in flight: `Builder::setRequestState()` and `RequestStateCodec` take a list of keys, the first signing and all of them verifying. The wire format is unchanged.
  * Fix a `subscriptions/listen` stream that can carry nothing, or that asks for resource subscriptions without a notification bus, being held open for its whole lifetime and pinning a PHP-FPM worker: it is acknowledged with an empty filter and closed right away. `server/discover` no longer announces `resources.subscribe` without a bus, unless `setCapabilities()` sets it. Adds `NotificationFilter::isEmpty()`.
  * Fix a retried `resources/read` leaving out the required `ttlMs` and `cacheScope`, which the TypeScript client rejects: it always carries `ttlMs: 0` and `cacheScope: private`. A `ReadResourceResult` no longer sends its own hints to handshake-era clients.
  * Fix `StandardHeaderValidator` accepting an `Mcp-Param-*` header that does not match the body: a fraction that rounds to an integer argument, object and array arguments, and integers outside ±(2^53-1) are refused with `-32020`.
  * Fix a request with `"id": null` being taken for a notification and left unanswered: it is refused with `-32600`.
  * Fix a result that cannot be encoded as JSON escaping as an uncaught `JsonException` or ending a stream without an answer: the client gets `-32603`, with HTTP status `500` or as the last frame of a stream, and the failure is logged and dispatched as an `ErrorEvent`.
  * Fix messages with a `jsonrpc` other than `"2.0"` being served: a `2026-07-28` request that skips `MessageFactory`, like `subscriptions/listen`, is refused with `-32600`, and `Notification::fromArray()` throws, as `Request::fromArray()` already did.
  * [BC Break] Remove `Rev2025Codec`, which nothing used.
* Event loops and fibers
  * Add `Builder::setFiberScheduler()` and `FiberSchedulerInterface`: under an event loop (Revolt, ReactPHP's async), a handler awaiting I/O suspends its fiber for the loop, and the server waits through the scheduler until the handler suspends for the server or returns. The scheduler must wake the server on a later turn of the loop; the guide shows one for Revolt and one for ReactPHP.
  * Add `CallbackStream::chunks()`, which hands the SSE text of a streamed response, in both protocol eras, to a server that writes the body itself (e.g. `amphp/http-server`, `react/http`). On an empty chunk, the server waits on its loop before it asks for the next one, and dropping the generator ends the stream. While a handshake-era handler waits for the client, the server keeps serving its loop. The Revolt and ReactPHP examples stream these responses instead of answering `501`.
  * Add `Builder::setFiberContext()` and `FiberContextInterface` to carry execution context, e.g. the active OpenTelemetry context, into handler fibers.
  * [BC Break] A `subscriptions/listen` stream read from `StatelessProtocol::handle()` no longer waits between polls or checks `connection_aborted()`: its consumer paces it, as `StatelessResponder` does.
  * [BC Break] `StreamableHttpTransport` no longer has the protected `flushOutgoingMessages()` and `handleFiberTermination()`.
  * [BC Break] A handler calling `\Fiber::suspend()` itself with anything but a `NotificationSuspension` or `RequestSuspension` fails the request without a scheduler, instead of being resumed with `null`.
  * Fix the server resuming a fiber the runtime had suspended, which made the handler's await return early or throw and could terminate a Revolt loop. Without a scheduler, such a handler fails the request with a `LogicException` naming `Builder::setFiberScheduler()`. `Protocol` and `StatelessProtocol` take the scheduler as optional last constructor argument.
  * Fix what a handler throws while the runtime drives its fiber escaping to the runtime: it reaches the server, and what it throws while its fiber is discarded is logged.
  * Fix a request failing with `503`, or a resumed one losing its session writes, while another request of the session holds the lock across an await: both wait for the lock on the loop.
  * Fix a handler still running after its request was answered firing `ResponseEvent` or `ErrorEvent`: what it throws is logged, and calling the `ClientGateway` throws.
* stdio
  * Fix a stdio server on Windows ending by itself after about 13.5 hours without input: `StdioTransport` reads only once `stream_select()` reports input. Requires PHP 8.5 on Windows.
  * Fix a stdio server never answering a message that reaches it in several reads, such as a tool call with large arguments.
  * Fix a stdio server answering a notification it refuses, e.g. one sent before `initialize`: the refusal is only logged.
  * Fix `maxLineBytes` of `StdioTransport` discarding lines two bytes shorter than the limit, and `StdioTransport` throwing a `ValueError` on an input that cannot be selected, like `php://memory`.

### Client

* Authorization
  * Add OAuth authorization: pass `OAuth::forApplication('My App')->build()` as `auth` and the client answers a `401` with discovery, dynamic or pre-registered client identity, the authorization code flow with PKCE and a retry. It refreshes tokens, handles step-up challenges and treats the server's metadata as untrusted input. See [Authorization](docs/client/authorization.md).
  * Add `OAuth::forServiceAccount()` for the client credentials grant, `setPrivateKeyJwt()` for `private_key_jwt` client authentication, the experimental `setCrossAppAccess()` to reach a server through an enterprise identity, and `setClientMetadataUrl()` for a client ID metadata document. `setExpectedIssuer()` is required with `setClientCredentials()`, so a hostile MCP server can't receive the secret or an authorization code.
  * Add `LoopbackAuthorizationHandler`, `HeadlessAuthorizationHandler`, `ConsoleAuthorizationHandler` for a shell without a browser, and `RedirectAuthorizationHandler` with `OAuthAuthenticator::finishAuthorization()` to finish in a later request, such as a web callback. Credentials go to `InMemoryCredentialStorage` or `FileCredentialStorage`, which also implements `PendingAuthorizationStorageInterface`.
  * Add the `auth` parameter of `HttpTransport` and `AuthenticatorInterface`, to authenticate every request and retry the ones the server refuses. `BearerToken` sends a token you already have, and `AuthenticatingHttpClient` offers the same as a PSR-18 decorator.
  * [BC Break] A `401` or `403` answer to a request, a notification or a stream resumption fails with an `AuthorizationException` holding the `WWW-Authenticate` header, instead of a `RequestException`. `Client::connect()` wraps it in a `ConnectionException`, without a retry.
* HTTP transport
  * [BC Break] Turn off redirects on a `symfony/http-client` client, discovered or passed, in `HttpTransport`, `AuthenticatingHttpClient` and `OAuth`. `HttpTransport` fails on every `3xx` answer with an error that names the status and target, while `AuthenticatingHttpClient` returns it unchanged. A redirect to another origin used to receive the configured headers, like an API key, and on a `307` or `308` the body: rotate those credentials if an endpoint of yours may have redirected to a host you don't control. Configure the final URL of an endpoint reached through a redirect. A client you pass must not follow redirects to another origin.
  * [BC Break] Limit what `HttpTransport` reads of a response that is not an SSE stream to the new `maxResponseBodyBytes` (default: 8 MiB): a larger successful response fails the request, and an error body is cut at the limit.
  * Fix `HttpTransport` taking an `Mcp-Session-Id` from any response instead of only the answer to `initialize`, which replaced the session mid-way and, on `2026-07-28`, made `close()` send a `DELETE`.
  * Fix `HttpTransport` not reading the listening stream during a request answered with plain JSON.
* stdio on Windows
  * [BC Break] Start a stdio server without `cmd.exe`, which corrupted arguments containing `"`, `%` or `!`. A batch file like `npx` needs `command: 'cmd'` and `args: ['/c', 'npx', ...]`.
  * Fix the stdio client hanging while the server stays silent, ignoring its timeouts. Requires PHP 8.5.
* Fix `Client::connect()` leaving the previous transport open when called again.

### Schema

* [BC Break] `MessageFactory::create()` returns one `MessageInterface` and throws `InvalidInputMessageException` for invalid input, instead of returning a list of messages and exceptions.
* [BC Break] Remove `MessageFactory::DEFAULT_MAX_BATCH_SIZE` and the `$maxBatchSize` parameter of `MessageFactory::__construct()` and `MessageFactory::make()`, whose first parameter is now `$additional`.
* Add `UnknownMethodException`, a subclass of `InvalidInputMessageException` for a method the factory has not registered.
* [BC Break] An `x-mcp-header` annotation that is not on a property reached through `properties` alone, e.g. under `items`, `anyOf` or `$defs`, or that is null, makes the tool definition invalid: `Tool` throws an `InvalidArgumentException`, and the client drops such a tool from `tools/list` and refuses a sampling request carrying one.
* [BC Break] `CallToolResult` keeps an explicit `structuredContent: null` when constructed and decoded. Code that passed null to omit it must leave the argument out (use `meta: $meta` for metadata) or pass `StructuredContent::Absent`. An explicit null that violates the output schema becomes a tool error, and strict handshake-era clients can reject it. `hasStructuredContent()` tells absent content from null; `success()` and `error()` stay without structured content.

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
