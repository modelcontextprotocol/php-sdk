# Architecture & OOP audit

Static audit of `src/` (306 files, ~32k LOC) focused on architectural structure and
object-oriented design, not on style or micro-optimisation. Every finding cites the code it
comes from.

The test suite could not be executed in the audit environment (`composer install` failed on
network access to GitHub), so everything below is derived from reading the source. Findings
marked **latent** describe a defect that exists in the code but that no current call path
reaches.

**Overall picture.** The protocol modelling is genuinely good — `InboundClassifier`,
`RequestStateCodec`, `Rev2026Codec` and the `Schema\*` value objects are careful, well-documented
work. The damage is concentrated in the *plumbing between layers*: how a request reaches a
handler, how a handler reaches the client, and how the builder wires the two. That plumbing was
grown incrementally, and it now carries the whole 2026-07-28 lifecycle on top of abstractions
that were designed for the 2025 one. Six of the ten architectural findings below are downstream
of a single root cause: **`SessionInterface` is used as the request context**.

---

## Contents

- [A. Architectural findings](#a-architectural-findings) — A1…A10
- [B. Systemic OOP anti-patterns](#b-systemic-oop-anti-patterns) — B1…B9
- [C. Concrete defects](#c-concrete-defects) — C1…C7
- [D. Suggested order of work](#d-suggested-order-of-work)

---

## A. Architectural findings

### A1. `Mcp\Capability` and `Mcp\Server` depend on each other

7 files under `src/Capability/` import from `Mcp\Server\`; 13 files under `src/Server/` import
from `Mcp\Capability\`. The cycle is not incidental — it runs through the most central classes:

| File | Imports from `Mcp\Server` |
| --- | --- |
| `Capability/Registry/ReferenceHandler.php:16-18` | `ClientGateway`, `RequestContext`, `Session\SessionInterface` |
| `Capability/Registry/Loader/ExplicitElementLoader.php:21-25` | `ClientGateway` + all four `Server\Handler\*HandlerInterface` |
| `Capability/Registry/Loader/ReflectedElementLoader.php:33` | `Mcp\Server\Handler` |
| `Capability/Discovery/SchemaGenerator.php:18` | `RequestContext` |
| `Capability/Logger/ClientLogger.php:15-17` | `ClientGateway`, `Protocol`, `SessionInterface` |

`ClientLogger` imports the whole `Protocol` class to read one session-key constant.

There is no seam here: the capability/registry layer cannot be used, tested or extracted without
the server, and the server cannot be reasoned about without the registry. Any future split into
`mcp/schema` + `mcp/server` + `mcp/client` packages is blocked by this.

**Direction.** Introduce a neutral contract layer (`Mcp\Element\` or `Mcp\Capability\Contract\`)
holding the four element-handler interfaces plus an `ExecutionContextInterface`. `Mcp\Server`
implements it; `Mcp\Capability` depends only on it. That single move breaks every arrow in the
table above.

---

### A2. `SessionInterface` is used as a request-scoped service locator — the root cause

This is the finding to fix first; A3, A5, A7, A8 and B1 are all consequences of it.

`Session` is a JSON-backed, dot-notation string→mixed bag (`Server/Session/Session.php`). It is
currently used to carry, all at once:

1. genuine cross-request session state (`initialized`, `client_info`, `protocol_version`);
2. per-request protocol metadata (`Protocol::SESSION_ACTIVE_REQUEST_META`, `RequestMeta::class`);
3. a per-request outbound message queue and pending-request table (`_mcp.outgoing_queue`,
   `_mcp.pending_requests`, `_mcp.responses.<id>`);
4. **live service objects** — `Protocol.php:270` and `StatelessProtocol.php:465` both write a
   `RequestStateCodec` *into the session*, and `RequestContext::mintRequestState()`
   (`RequestContext.php:140`) reads it back out to use it.

Point 4 is a service locator wearing a session's clothes, and it does not survive the session's
own contract. `Session::save()` is `json_encode($this->readData())` — an object with only
private properties encodes to `{}`. So `RequestStateCodec` and `InputContext`
(`InputRequiredShim.php:116`) are silently destroyed the moment a session is persisted to
`FileSessionStore` or `Psr16SessionStore`. It happens to work today only because both are
re-written on every request before anything reads them.

The shape ambiguity this creates is already visible in `ClientGateway::hasSubCapability()`
(`ClientGateway.php:374-386`), which has to handle its value being *either* an array *or* an
object depending on whether the session has round-tripped through JSON:

```php
$capabilities = (array) $this->session->get('client_capabilities', []);
$declared = $capabilities[$capability] ?? null;

if (\is_array($declared)) {
    return \array_key_exists($name, $declared);
}

return \is_object($declared) && property_exists($declared, $name);
```

There is a typed `Schema\ClientCapabilities` class. It is thrown away at
`StatelessProtocol.php:447` (`->jsonSerialize()`) and this reconstructs it by hand, twice, in
five methods.

The magic keys are spread across six files with no single owner: `_mcp.request_id_counter`,
`_mcp.pending_requests`, `_mcp.responses`, `_mcp.outgoing_queue`, `_mcp.active_request_meta`,
`_mcp.logging_level`, `client_capabilities`, `protocol_version`, plus three class-name keys.

**Direction.** Split the three concerns that are currently one object:

- `ExecutionContext` — an immutable, typed, per-request value object (request, client
  capabilities, protocol version, trace context, `InputContext`, progress token). Passed as an
  argument, never stored.
- `OutboundChannel` — the queue/pending/response correlation machinery, owned by `Protocol`.
- `SessionInterface` — reduced to what genuinely must outlive a request, with typed accessors
  instead of `get(string $key, mixed $default)`.

The codec is a constructor dependency of whatever mints state, not a session entry.

---

### A3. `StatelessProtocol` fabricates a fake session to satisfy the handler interface

Because handlers take a `SessionInterface`, the stateless era — which by definition has no
session — has to invent one per request (`StatelessProtocol.php:442-465`):

```php
$session = new Session(new InMemorySessionStore());
$session->set(RequestMeta::class, $meta);

// Under the same keys the handshake era writes, so everything reading
// connection state — ClientGateway's capability probes above all — sees
// this request's declaration instead of an empty session.
$session->set('client_capabilities', $meta->clientCapabilities->jsonSerialize());
$session->set('protocol_version', $meta->protocolVersion);
// … three more set() calls
```

The comment is honest about what is happening: the object is being shaped to satisfy readers
that should never have been given a session in the first place. A store is allocated per
request purely to be discarded, and the two eras are now coupled through *the string keys they
both happen to write*, which is the most fragile coupling available.

`RequestHandlerInterface::handle(Request $request, SessionInterface $session)` should be
`handle(TRequest $request, ExecutionContext $context)`. That deletes this block outright.

---

### A4. Two protocol classes duplicate dispatch, and have already drifted

`Server\Protocol` (723 L) and `Server\Stateless\StatelessProtocol` (815 L) each independently
implement: iterate handlers → `supports()` → run in a `\Fiber` → interpret the suspension
payload → map exceptions to JSON-RPC error codes → encode. The class docblock justifies the
split ("the two eras share request handlers, not control flow"), which is fair for framing and
lifecycle — but not for the four things above, which are era-independent.

They have already diverged in ways that are hard to defend as intentional:

| Behaviour | `Protocol` | `StatelessProtocol` |
| --- | --- | --- |
| `RequestEvent` / `ResponseEvent` / `ErrorEvent` dispatch | yes | **no** |
| `MissingRequiredClientCapabilityException` → `-32021` | no | yes (`:718`) |
| `LogicException` → message echoed to client | no | yes (`:729`) |
| `\InvalidArgumentException` → `-32602` | yes (`:327`) | yes (`:725`) |

So a server that answers both eras from one `Builder` fires PSR-14 events for handshake traffic
and stays silent for modern traffic, and maps the same handler exception to two different
JSON-RPC codes depending on which leg answered. Neither is documented as intended.

**Direction.** Extract `HandlerDispatcher` (find handler, run fiber, collect notifications) and
`JsonRpcErrorMapper` (throwable → `Error` + HTTP status) as shared collaborators. The two
protocol classes keep only what is genuinely era-specific: session resolution and replay for one,
per-request envelope and streaming frames for the other. Expect them to lose roughly half their
lines.

---

### A5. Transport↔Protocol coupling is six setter-injected callbacks

`Server\Transport\TransportInterface` requires implementers to accept six callbacks:
`onMessage`, `onSessionEnd`, `setOutgoingMessagesProvider`, `setPendingRequestsProvider`,
`setResponseFinder`, `setFiberYieldHandler`. `ManagesTransportCallbacks` stores them as six
untyped `protected $x` properties (PHP has no `callable` property type), and `BaseTransport`
guards every single use:

```php
protected function getOutgoingMessages(?Uuid $sessionId): array
{
    if ($sessionId && \is_callable($this->outgoingMessagesProvider)) {
        return ($this->outgoingMessagesProvider)($sessionId);
    }

    return [];   // silently
}
```

Consequences:

- A transport used without `Protocol::connect()` **silently does nothing** — no messages, no
  session teardown, no fiber resumption — instead of failing loudly. All five accessors return
  an empty/null default.
- Static analysis cannot verify any of the six signatures; they exist only as `@var` comments.
- The transport's real collaborator (the protocol) is invisible in its constructor, so the
  dependency graph cannot be read from the types.
- `connect()` before `listen()` is temporal coupling that nothing enforces.

The client side repeats the pattern with five more (`setState`, `onInitialize`, `onMessage`,
`onError`, `onHeaders` — `Client/Protocol.php:128-158`).

`ManagesTransportCallbacks` is also used by exactly one class (`BaseTransport`), so the
trait/abstract-class pair is indirection with no reuse behind it.

**Direction.** One narrow interface — call it `ProtocolGatewayInterface` — with the six methods
as real methods, passed to `connect()` or the constructor. The trait and all `is_callable()`
guards disappear, and "transport not connected" becomes a type error rather than silence.

---

### A6. `Builder` is a 1167-line god object with a memoised, unguarded build

| Metric | Value |
| --- | --- |
| Lines | 1167 |
| Fields | 49 |
| Methods | 44 |

It is simultaneously: a configuration collector for four element kinds × two registration
styles (8 separate collection fields), a service-defaulting factory, a capability detector, an
extension registry, and the wiring root for both protocol eras.

Three specific problems inside it:

**Memoised build on a mutable builder.** `assemble()` caches into `$this->parts` (`:998`), but
every setter remains callable afterwards and none invalidates the cache:

```php
$server = $builder->build();
$builder->addTool($another);   // accepted, no error
$other  = $builder->build();   // silently returns the first wiring
```

The memo is correct and necessary — both eras must share one registry — but it makes the builder
single-use, and nothing says so.

**Uninitialised typed property plus a parallel flag.** `private RegistryInterface $registry;`
(`:94`) has no default, guarded by a separate `private bool $hasCustomRegistry = false;`
(`:258`). Two fields encoding one nullable value; `?RegistryInterface $registry = null` says the
same thing with one field and no invariant to keep in sync.

**`compact()` arrays as DTOs.** `addTool()`, `addResource()`, `addResourceTemplate()` and
`addPrompt()` each push a `compact(...)` bag of 6-10 keys. Their PHPStan array shapes are
declared on the Builder property *and* duplicated verbatim as `@param` on
`ReflectedElementLoader::__construct()` — about 45 lines of annotation maintained in two files.
Adding one field to a tool registration means editing three places.

**Direction.** `ToolRegistration`/`ResourceRegistration`/… readonly VOs replace the bags and the
duplicated shapes. `CapabilityDetector` takes over `detectCapabilities()` (currently two
near-duplicate branches, `:1117-1166`). `ServerFactory` takes over `resolve()`. Either make
`build()` throw on post-assembly reconfiguration, or have the builder emit an immutable
`ServerSpec` that the factory consumes.

---

### A7. `supports()` + `\assert()` is hand-rolled dispatch that defeats the type system

Every one of the 20 request/notification handlers opens the same way:

```php
public function supports(Request $request): bool
{
    return $request instanceof CallToolRequest;
}

public function handle(Request $request, SessionInterface $session): Response|Error
{
    \assert($request instanceof CallToolRequest);

    $toolName = $request->name;      // ← property that only exists on the subtype
```

`assert()` is compiled out under `zend.assertions=-1`, which is the recommended production
setting. So in production a mis-routed request does not fail at the assertion; it reaches
`$request->name` and dies with an undefined-property error inside the handler. The type
information exists (`supports()` knows it) but is discarded at the interface boundary and
re-asserted 20 times.

**Direction.** Make the interface carry the type:

```php
/**
 * @template TRequest of Request
 * @template-covariant TResult
 */
interface RequestHandlerInterface
{
    /** @return class-string<TRequest> */
    public static function handles(): string;

    /** @param TRequest $request */
    public function handle(Request $request, ExecutionContext $context): Response|Error;
}
```

The dispatcher narrows once, by class-string lookup rather than a linear `supports()` scan, and
`supports()` + 20 `assert()` lines go away.

---

### A8. `ReferenceHandler` is four classes in one, held together by two magic conventions

`Capability/Registry/ReferenceHandler.php` (297 L) is at once an invoker, a mini-DI container, an
argument type-caster, and a special-parameter injector. Both of the conventions holding it
together are invisible to the type system.

**Convention 1 — magic keys in the user's argument array.** `CallToolHandler.php:100-101` writes
into the *client-supplied* tool arguments:

```php
$arguments['_session'] = $session;
$arguments['_request'] = $request;
```

`ReferenceHandler.php:45` then reads `$arguments['_session']` with no guard at all — a missing
key is an undefined-index error, not a diagnosable failure. The collision hazard is real enough
that `SchemaGenerator.php:540` has to *ban* handler parameters named `_session` or `_request`:

```php
if (\in_array(strtolower($paramName), ['_session', '_request'], true)) {
    throw new InvalidArgumentException(\sprintf('Handler method "%s::%s" has parameter named "%s" which is not allowed…'));
}
```

A design that has to forbid identifiers to protect itself is telling you the channel is wrong.
Context belongs in a second parameter, not smuggled through the first one's array.

**Convention 2 — closure scope used as a type tag.** `ExplicitElementLoader::boundClosure()`
(`:101`) binds each closure to `ReferenceHandler::class` as its scope, and
`ReferenceHandler::handle()` (`:38-42`) sniffs for it to switch calling convention:

```php
if ($reference->handler instanceof \Closure
    && self::class === (new \ReflectionFunction($reference->handler))->getClosureScopeClass()?->getName()
) {
    return ($reference->handler)($arguments);   // raw bag, skip reflection mapping
}
```

This makes `Builder::add()` **silently incompatible with `Builder::setReferenceHandler()`**: a
custom `ReferenceHandlerInterface` implementation knows nothing about the scope convention, so
every element registered through `add()` gets reflection-mapped arguments instead of the raw
bag its wrapper expects. A loader in `Capability\Registry\Loader\` is depending on the
*implementation* `ReferenceHandler`, not on `ReferenceHandlerInterface` — and the interface has
no way to express the difference.

**Direction.** Put the calling convention on the reference where it belongs — `ElementReference`
carries an `Invocation` strategy (`RawBag` vs `ReflectionMapped`) instead of the invoker
guessing. Extract `ArgumentCaster` (the `castToInt`/`castToFloat`/`castToBoolean`/`castToArray`
family, `:175-296`) and make the injectable-parameter set a list of
`ArgumentResolverInterface` rather than two hardcoded `if` branches.

---

### A9. Element metadata knowledge is duplicated three ways and has already drifted

**Injectable parameter types — drifted, with a user-visible consequence.**
`ReferenceHandler::prepareArguments()` injects two types by name (`:111-118`): `RequestContext`
**and** `ClientGateway`. `SchemaGenerator::parseParametersInfo()` (`:534`) skips only one:

```php
if (is_a($typeName, RequestContext::class, true)) {
    continue;
}
```

So a handler declared as `#[McpTool] public function search(string $q, ClientGateway $gateway)`
publishes a phantom `gateway` property in its `inputSchema` — and `CallToolHandler.php:82`
validates incoming arguments against that schema before invoking, so callers can be rejected for
omitting an argument they cannot supply. No test or fixture covers this: every fixture under
`tests/Integration/Fixture/` takes `RequestContext` and reaches the gateway via
`$context->getClientGateway()`, which is the path that happens to work. **Latent**, but a
one-line user mistake away.

**Name and description derivation — copy-pasted eight times.** The expression

```php
$name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
$description = $instance->description ?? $this->docBlockParser->getDescription($docBlock) ?? null;
```

appears four times in `Discoverer::processMethod()` (once per `switch` branch) and four more
times in `ReflectedElementLoader::load()`, with small differences in the closure case.

**Direction.** One `ElementMetadataFactory` owning name/description/schema derivation, consumed
by both the discoverer and the reflected loader. One `InjectableParameters` list that both
`ReferenceHandler` and `SchemaGenerator` read, so the two cannot drift again.

---

### A10. `Registry`: a 20-method interface with four copy-pasted CRUD blocks

`RegistryInterface` declares 20 methods; `Registry` implements 28 in 451 lines. The four element
kinds each get `register` / `unregister` / `has` / `hasX` / `get` / `getX`, all byte-identical
modulo names. `getTools`, `getResources`, `getResourceTemplates` and `getPrompts` differ only in
the field they iterate and the key they index by.

The pagination inside them is inconsistent with itself. Both helpers decode the cursor
independently and disagree about what an invalid one means:

```php
// paginateResults(), :438 — invalid cursor is an error
if (false === $decodedCursor || !is_numeric($decodedCursor)) {
    throw new InvalidCursorException($cursor);
}

// calculateNextCursor(), :406 — same invalid cursor is silently offset 0
if (false !== $decodedCursor && is_numeric($decodedCursor)) {
    $currentOffset = (int) $decodedCursor;
}
```

Same method also returns two different shapes: `getTools(null)` returns a name-keyed map,
`getTools(50)` returns a list (`array_values()` inside `paginateResults`). Callers are saved
only because `ListToolsResult::jsonSerialize()` re-applies `array_values()`.

No client of `RegistryInterface` needs all 20 methods — a textbook interface-segregation
violation, and the reason every test double for it is large.

**Direction.** A generic `ElementCollection` keyed by an `ElementKind` enum collapses the four
blocks into one. A `Paginator` collaborator owns cursor encode/decode in one place. Split the
interface into `ElementRegistry` (reads, what handlers need) and `MutableElementRegistry`
(writes, what loaders need).

---

## B. Systemic OOP anti-patterns

### B1. Arrays standing in for objects

- **Builder registrations** — `compact()` bags (see A6).
- **`SchemaGenerator`'s `ParameterInfo`** — a 12-key array (`SchemaGenerator.php:30-44` (the `ParameterInfo` shape)) threaded
  through ten private methods by value and by reference. The PHPStan shape is the only thing
  keeping it honest.
- **Fiber suspension payloads** — `ClientGateway::notify()` (`:92-99`) and `::request()` (`:419-431`)
  suspend with `['type' => 'notification'|'request', …]`. That untyped shape is then re-read and
  re-validated by string comparison in *three* separate places:
  `Protocol::handleRequest()` (`:300-315`), `Protocol::handleFiberYield()` (`:587-640`), and
  `StatelessProtocol::readNotification()` (`:587-613`). Each does its own `is_array` /
  `isset($x['type'])` / `instanceof` dance, and `StatelessProtocol` throws a `LogicException` for
  a `'request'` payload the other two accept. A `Suspension` VO hierarchy
  (`NotificationSuspension`, `RequestSuspension`) replaces all three with a `match` on type.

### B2. Registry value objects carrying behaviour and building their own dependencies

`ToolReference` is a registry entry — a `Tool` plus a handler. It also owns result formatting and
protocol-version-dependent structured-content extraction (`ToolReference.php:55-142`), and
constructs its collaborator inside the method:

```php
public function formatResult(mixed $toolExecutionResult): array
{
    return (new ToolResultFormatter())->format($toolExecutionResult);
}
```

The formatter cannot be swapped, decorated or mocked, and `ToolResultFormatter` exists as a
separate injectable class already — so the seam was built and then bypassed. Same shape in
`PromptReference::formatResult()` and `ResourceReference`. Result formatting is a presentation
concern for the handler, not data on the registry entry.

### B3. `Page extends \ArrayObject`

```php
final class Page extends \ArrayObject
{
    public function __construct(
        public readonly array $references,
        public readonly ?string $nextCursor,
    ) {
        parent::__construct($references, \ArrayObject::ARRAY_AS_PROPS);
    }
```

Three problems in five lines: the data is stored twice (property and parent), so the two can
diverge; `readonly` is a lie because `ArrayObject::append()` and `offsetSet()` remain public on
the "immutable" value object; and `ARRAY_AS_PROPS` makes `$page->nextCursor` ambiguous between
the property and an item keyed `nextCursor`. Inheritance was used where `IteratorAggregate` +
`Countable` was meant.

### B4. Stringly-typed control flow across a module boundary

`StatelessProtocol::dispatch()` (`:418`) decides between HTTP 404 and 400 by comparing an
exception's message text against a string built in another class:

```php
$unknownMethod = \sprintf('Unknown method "%s".', $method) === $request->getMessage();

return StatelessResult::error(
    $unknownMethod ? $this->unknownMethod($method, $id) : Error::forInvalidRequest($request->getMessage(), $id),
    $unknownMethod ? 404 : 400,
);
```

The producer is `MessageFactory::findMessageClassByMethod()` (`:214`). Rewording that message —
or localising it, or adding punctuation — silently changes the status code this endpoint
returns. A dedicated `UnknownMethodException` subtype makes the check a type check.

### B5. Type switches instead of polymorphism

- `Discoverer::processMethod()` (`:227-300`) — a 90-line `switch ($attributeClassName)` over four
  attribute classes, taking **five by-reference out-parameters**
  (`&$discoveredCount, &$tools, &$resources, &$prompts, &$resourceTemplates`). This is procedural
  code in a class. Each attribute could build its own element, or a per-attribute factory could,
  with a `DiscoveryState` accumulator returned rather than mutated through references.
- `Builder::add()` (`Builder.php:804-810`) — `match (true)` over four definition/handler pairings, with a
  `default => throw` for mismatches that the type system could have caught with four overloaded
  methods or a `Registration` union.
- `ReferenceHandler::handle()` (`ReferenceHandler.php:38-79`) — cascading `is_string` / `function_exists` /
  `is_callable` / `is_array` checks reimplementing dispatch over the `Handler` union.

### B6. Two competing exception roots, and the choice decides what leaks to clients

The SDK defines `Mcp\Exception\Exception`, `Mcp\Exception\RuntimeException` and
`Mcp\Exception\InvalidArgumentException` as bases — but of 29 concrete exceptions, only **5**
extend `Mcp\Exception\Exception`, while **13** extend the *global* `\RuntimeException` or
`\InvalidArgumentException` directly and merely implement `ExceptionInterface`:

```
ToolCallException            extends \RuntimeException        implements ExceptionInterface
SamplingException            extends \RuntimeException        implements ExceptionInterface
ElicitationException         extends \RuntimeException        implements ExceptionInterface
RootsException               extends \RuntimeException        implements ExceptionInterface
PromptGetException           extends \RuntimeException        implements ExceptionInterface
ResourceReadException        extends \RuntimeException        implements ExceptionInterface
HandlerNotFoundException     extends \InvalidArgumentException implements NotFoundExceptionInterface
InvalidCursorException       extends \InvalidArgumentException implements ExceptionInterface
InvalidInputMessageException extends \InvalidArgumentException implements ExceptionInterface
…
```

So `catch (\Mcp\Exception\RuntimeException $e)` — the obvious thing for a user to write — catches
almost none of the SDK's runtime exceptions. `ExceptionInterface` is the real root and the two
concrete base classes are decoration.

This is not merely cosmetic. Both protocol classes branch on `\InvalidArgumentException` to
decide whether an exception's message is safe to echo to the peer
(`Protocol.php:327`, `StatelessProtocol.php:725`). Which base class an exception happens to
extend therefore silently determines whether its text reaches the client — a security-adjacent
decision currently made by copy-paste.

### B7. `Container::has()` breaks the PSR-11 contract, with a downstream effect

```php
public function has(string $id): bool
{
    return isset($this->instances[$id]) || class_exists($id) || interface_exists($id);
}
```

This returns `true` for *any* class that exists, including ones this container demonstrably
cannot build (`resolveParameter()` throws for scalar constructor parameters without defaults).
`ReferenceHandler::getClassInstance()` (`:83-90`) is written against the correct contract:

```php
if (null !== $this->container && $this->container->has($className)) {
    return $this->container->get($className);
}

return new $className();   // ← unreachable with the default container
```

With the default `Container`, the `new $className()` fallback never runs, and a handler class
with, say, a `string $apiKey` constructor parameter fails with `ContainerException` instead of
falling back. `has()` should mirror what `get()` can actually do.

The class also carries visible rush marks — numbered step comments running `1, 2, 7, 3, 4, 5, 6,
7` (`Container.php:53-112`) — and lives under `Mcp\Capability\Registry\`, which is not where a
DI container belongs.

### B8. A PSR-7 transport writing to global output

`StreamableHttpTransport` builds a `CallbackStream` body and then, inside it, bypasses PSR-7
entirely:

```php
echo "event: message\n";
echo "data: {$message['message']}\n\n";
@ob_flush();
flush();
```

(`:330-333` and `:313-316`.) This makes SSE output untestable without output buffering, and ties
a PSR-7 transport to the SAPI it happens to run under.

Two more pieces of mutable state in the same class:

- `send()` (`:160-164`) stashes into `$this->immediateResponse` / `$this->immediateStatusCode`, which
  `handlePostRequest()` (`:184-202`) reads back. Neither is ever cleared, so a transport instance
  reused for a second POST replays the first response.
- `handleRequest()` (`:379`) reassigns `$this->request` mid-flight, although PSR-7 requests are
  immutable precisely so that this is unnecessary.

The polling loop (`:239-285`) also uses raw `time()` for timeouts while the codebase already has
`NativeClock`/`ClockInterface`, injected in both session stores — so the SDK is half-way to
testable time and this is the untestable half.

### B9. Error suppression as flow control

16 `@`-suppressed calls, concentrated in `FileSessionStore` (`@mkdir`, `@filemtime` ×3,
`@unlink` ×5, `@file_get_contents`, `@file_put_contents`, `@rename`, `@copy`, `@touch`,
`@opendir`). Failures become `false`, are mapped to "no session", and nothing is logged — so a
permissions problem on the session directory presents as clients being silently logged out.
`FileSessionStore` takes no logger.

---

## C. Concrete defects

These are bugs rather than design opinions, ordered by severity.

### C1. `FileSessionStore::gc()` deletes any file in its directory, then checks the name

```php
$mtime = @filemtime($path) ?: 0;
if (($now - $mtime) > $this->ttl) {
    @unlink($path);                       // ← delete
    try {
        $deleted[] = Uuid::fromString($entry);   // ← then validate the name
    } catch (\Throwable) {
        // ignore non-UUID file names
    }
}
```

(`FileSessionStore.php:136-143`.) The validation is only used to decide whether to *report* the
deletion; the `unlink` already happened. Because the constructor happily accepts an existing
directory (`:30-37`), pointing the store at anything shared — a general cache dir, `/tmp`, a
directory that also holds a lockfile — means GC deletes unrelated files older than the TTL.

Fix: validate the filename as a UUID *before* unlinking, and skip anything else.

### C2. `ClientGateway` parameters leak into the published `inputSchema`

See A9. **Latent** — no fixture exercises it — but it silently produces a wrong tool schema and a
call that cannot succeed.

### C3. Registry list-changed events fire during the initial load

`Registry::registerTool()` dispatches `ToolListChangedEvent` unconditionally (`Registry.php:111`), including
while `$this->loader->load($this)` is running. With a notification bus configured, `Builder`
wraps the dispatcher in `PublishingEventDispatcher`, so building a server with N tools publishes
N `notifications/tools/list_changed` frames onto the bus before any client exists. With
`Psr16NotificationBus` those land in shared cache.

The registry already tracks exactly the state needed to suppress this — `private bool $loading`
(`Registry.php:66`) — and does not consult it when dispatching. One-line class of fix: suppress during load,
emit one event per changed kind at the end.

### C4. `Protocol::handleRequest()` reassigns the request it is serving

```php
} elseif ('request' === $result['type']) {
    $request = $result['request'];       // ← the *outbound* request
    $timeout = $result['timeout'] ?? 120;
    $this->sendRequest($request, $timeout, $session);
}
```

(`:305-310`.) `$request` is the inbound request the whole method is about. After this line the
enclosing `catch` blocks (`:327-345`) still call `$request->getId()` — and the outbound request
has no id set (`sendRequest()` assigns one to a clone). If `sendRequest()` throws, the catch
hits an uninitialised typed property. **Latent**, and a one-word fix (rename the local).

### C5. Cursor validation disagrees with itself

See A10: an invalid cursor throws `InvalidCursorException` in `paginateResults()` and is silently
read as offset 0 in `calculateNextCursor()` — both called from the same `getTools()` invocation,
both decoding the same string.

### C6. `Session::forget()` writes while deleting

```php
while (\count($segments) > 1) {
    $segment = array_shift($segments);
    if (!isset($data[$segment]) || !\is_array($data[$segment])) {
        $data[$segment] = [];          // ← creates structure during a delete
    }
    $data = &$data[$segment];
}
```

(`Session.php:104-116`.) `forget('a.b')` on a session with no `a` leaves `a => []` behind. Same
loop body as `set()`, copy-pasted without removing the vivification.

### C7. `Request::jsonSerialize()` guards the wrong key

```php
if (null !== $this->meta && !isset($params['meta'])) {
    $array['params']['_meta'] = $this->meta;
}
```

(`Schema/JsonRpc/Request.php:114-127`.) The guard tests `meta`; the write targets `_meta`. No
current `getParams()` implementation emits `_meta`, so the guard is dead code today — but it
provides none of the protection it appears to. **Latent.**

---

## D. Suggested order of work

Sequenced so each step makes the next one smaller, and none requires a big-bang rewrite.

**Step 1 — safe, isolated fixes.** C1 (validate before unlink), C3 (suppress load-time events),
C4/C6/C7 (local corrections), B7 (`Container::has()`). No public API change; each is a small PR
with a regression test. C1 is the only one with a data-loss story, so it goes first.

**Step 2 — introduce `ExecutionContext` (A2).** Add the typed context object and populate it in
both protocols alongside the existing session writes. Nothing breaks yet; the session keys stay.
This is the pivot the rest depends on, so it is worth doing carefully and on its own.

**Step 3 — retype the handler interface (A3, A7).** Move handlers to
`handle(TRequest, ExecutionContext)` with `handles(): class-string`. Delete the fake-session
block in `StatelessProtocol`, the 20 `assert()` lines, and the `supports()` linear scan. Then
strip the now-unused per-request keys out of `Session` and give the remainder typed accessors.

**Step 4 — break the package cycle (A1).** With `ExecutionContext` in place, `ReferenceHandler`
and `ExplicitElementLoader` no longer need `Server\ClientGateway`; move the element-handler
interfaces into the neutral contract namespace. Add an architecture test (deptrac, or a PHPUnit
test over `composer dump-autoload` output) asserting `Capability` never imports `Server`, so the
cycle cannot come back.

**Step 5 — deduplicate dispatch (A4) and the element metadata (A9).** Extract
`HandlerDispatcher` + `JsonRpcErrorMapper`; extract `ElementMetadataFactory` and a shared
`InjectableParameters` list. Fixing A9 closes C2 as a side effect. Decide explicitly, and
document, whether PSR-14 events should fire for modern-era traffic.

**Step 6 — decompose `Builder` (A6) and `Registry` (A10).** Registration VOs, `CapabilityDetector`,
`ServerFactory`; `ElementCollection` + `Paginator`. Largest diff of the six, but by this point it
is mechanical, and it removes the duplicated PHPStan shapes.

**Step 7 — transport interface (A5, B8).** Replace the six callbacks with one gateway interface;
move SSE writes onto the PSR-7 stream; inject `ClockInterface` into the polling loop.

**Cross-cutting, do alongside:** normalise the exception hierarchy on `ExceptionInterface` (B6)
and stop using base-class identity to decide what is echoed to clients — introduce an explicit
`isSafeForClient()` or a dedicated `ClientFacingException` marker instead.

**Not recommended:** a rewrite. The protocol layer (`Schema\*`, `Server\Wire\*`,
`RequestStateCodec`, `InboundClassifier`) is the hard part and it is in good shape. Everything
above is plumbing around it.
