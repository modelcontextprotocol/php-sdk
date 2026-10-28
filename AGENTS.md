# AGENTS.md

This file provides guidance to AI coding agents working with code in this repository.

`mcp/sdk` is the official PHP SDK for the Model Context Protocol — server and client, framework-agnostic, PHP `^8.1`.
It is a PHP Foundation × Symfony collaboration and follows Symfony's coding standards and BC promise.

## Commands

```bash
composer install
npm ci --include=dev --prefix tests/Interop   # once; without it the interop suite fails with setup noise

make ci                  # composer update --prefer-stable, cs, phpstan, all PHPUnit suites
make cs                  # php-cs-fixer (auto-fixes; @Symfony + @Symfony:risky + file header)
make phpstan             # static analysis (phpstan.dist.neon + phpstan-baseline.neon)
make unit-tests          # tests/Unit
make integration-tests   # tests/Integration — our client against our server
make interop-tests       # tests/Interop — needs Node; MCP Inspector + TS reference server
make conformance-tests   # official conformance suite, 2025-11-25 (Docker + npx)
make conformance-draft   # same for 2026-07-28
make docs-guides         # Zensical build of docs/ in --strict mode (via uv)

vendor/bin/phpunit tests/Unit/Server/ProtocolTest.php        # single file
vendor/bin/phpunit --filter testMethodName                   # single test
```

CI runs PHP 8.1–8.5 with lowest and highest dependencies; code must stay valid on PHP 8.1.
PHPUnit fails on warnings, risky tests and unexpected output.

Integration and interop tests snapshot their output; snapshots are written on first run — delete one to re-record it.
`tests/Integration/ClientExamples/` and `tests/Interop/Server/` have one test class per script in `examples/`, so
changing an example usually means updating its snapshot.

## Architecture

### Two protocol eras, one endpoint

The central design constraint (see `docs/protocol-versions.md`):

- **Handshake era** (≤ `2025-11-25`): `initialize` handshake, sessions (`Mcp-Session-Id`), server→client requests
  over the open channel. Dispatched by `Server\Protocol`.
- **Modern era** (`2026-07-28`, SEP-2575): stateless, version and capabilities declared per request in
  `params._meta`, discovery via `server/discover`, no server→client requests — a handler returns an
  `InputRequiredResult` and the client retries (MRTR). Dispatched by `Server\Stateless\StatelessProtocol`.

Both share the **request handlers**, not control flow. `StreamableHttpTransport` and `StdioTransport` serve both eras
(`StatelessAwareTransportInterface`); `Server\Wire\InboundClassifier` decides the era once per inbound request.
`Server\Wire\Rev2025Codec`/`Rev2026Codec` translate the revision-neutral result model into each era's wire shape, so
modern-only fields (`resultType`, caching hints, …) are stamped at the edge, not modelled on `Schema\Result\*`.
`InputRequiredShim` lets a modern-style handler that returns an input-required result run on a handshake connection
by issuing real `elicitation/create`/`sampling/createMessage` requests and re-running the handler.
`ProtocolVersion::isModern()` / `FIRST_MODERN_VERSION` mark the boundary.

### Server

- `Server::builder()` (`Server\Builder`) wires everything; `Server::run($transport)` connects `Protocol` (and
  `StatelessProtocol` if the transport supports it) and calls `$transport->listen()`.
- Request/notification handlers live in `Server\Handler\Request|Notification` and implement
  `RequestHandlerInterface`/`NotificationHandlerInterface`; custom ones go in via `Builder::addRequestHandler()` /
  `addNotificationHandler()`, extensions via `Builder::enableExtension()`.
- Handlers run inside **PHP Fibers**. `ClientGateway` (from `RequestContext`) suspends the fiber with a
  `Server\Suspension\RequestSuspension`/`NotificationSuspension`; the transport loop sends the message and resumes the
  fiber with the client's answer. Request ids to the client are UUIDs, matched per fiber, so concurrent requests of one
  session don't cross.
- `Server\Session\*`: session stores (in-memory, file, PSR-16) and optional per-session locks.
- `Server\Authorization\*`: resource-server-only OAuth (ADR 0001/0002 in `adr/`) — token validation, scope policy,
  protected resource metadata. Being an authorization server or proxying the OAuth flow is out of scope.
- `Server\Transport\Http\*`: PSR-15 middleware (CORS, DNS rebinding, authorization) in front of the HTTP transport.

### Capabilities (tools, resources, prompts)

- Elements are declared with attributes (`Capability\Attribute\McpTool`, `McpResource`, `McpPrompt`, …) or registered
  explicitly on the builder.
- `Capability\Registry\Loader\*` populate the `Registry`: `DiscoveryLoader` scans directories (needs
  `symfony/finder`), `ExplicitElementLoader` handles builder registrations, chained by `ChainLoader`.
- `Capability\Discovery\*` derives JSON schemas from signatures and docblocks; `Registry\ReferenceHandler` invokes the
  element, injecting `RequestContext` and container services; `Capability\Formatter\*` turns return values into
  content.

### Client

`Client::builder()` → `Client\Protocol` handles correlation and the handshake; `Client\Stateless\*` builds modern-era
request envelopes/headers and resolves input-required results. Transports in `Client\Transport` (stdio, HTTP with SSE
and reconnect policy).

### Schema and JSON-RPC

`Schema\*` holds the MCP types (requests, results, notifications, content, enums) as immutable value objects
serialized via `jsonSerialize()`; requests and notifications extend `Schema\JsonRpc\Request`/`Notification` and
implement `getMethod()`, `fromParams()` and `getParams()`. `JsonRpc\*` parses and builds raw messages. `Schema\Extension\*` is the generic seam
for protocol extensions (`ExtensionInterface`, e.g. `Apps`); core handlers, `RequestContext` and `Client` must not
reference concrete extension types.

## Conventions

- Throw `Mcp\Exception\*` (all implement `ExceptionInterface`), never native SPL exceptions.
- Add an `@author` tag to newly introduced classes.
- Define array shapes for parameters and return types.
- Prefer classic `if` statements over short-circuit evaluation when possible.
- Avoid `empty()`; prefer explicit checks like `[] === $array`, `'' === $string` or `null === $value`.
- Pre-1.0, BC breaks are allowed but every one needs a `[BC Break]` entry in `CHANGELOG.md` under the unreleased
  version, grouped by component (`### Server`, `### Client`, …). Never add entries to an already released version.
- Spec-deprecated features get `@deprecated` plus `trigger_deprecation()` (see `docs/deprecation-policy.md`).
  `@internal` code is outside the BC promise.
- Every PHP file carries the license header enforced by php-cs-fixer.
- Docs under `docs/` link to each other with relative paths inside `docs/`; link repo-root files (`README.md`,
  `CHANGELOG.md`, `ROADMAP.md`) by their GitHub URL, or the strict Zensical build fails.
- New documented patterns usually come with an example under `examples/` (registered in `composer.json` autoload-dev)
  and its integration/interop test class.

## Grounding in the Specification

Ground protocol work in the MCP specification instead of assumptions: before implementing or changing protocol
behaviour — message shapes, capabilities, error codes, transport semantics — read the specification text of the
revision in question, or the SEP that introduces the change. Don't infer behaviour from another SDK (TypeScript,
Python, Java) or from a different protocol revision.

## Code Comments

Comments are the exception, not the default. Well-named classes, methods, and variables should carry the meaning; add
a comment only when the code cannot explain itself.

- Comment the *why*, never the *what*: a non-obvious constraint, a spec quirk, a workaround for a client interop
  issue. Don't narrate what the next line does.
- Describe the implementation as it is, not how it came to be. No references to the change, the ticket, the PR, the
  review, or a previous version ("fix for #123", "previously we…", "now also handles…", "as requested") — that context
  belongs in the commit message or PR description.
- Keep comments to a single short line. No multi-line rationale blocks; if a longer explanation is needed, it goes
  into the PR or the docs.
- Don't add PHPDoc that only repeats the native signature; use PHPDoc for what types can't express (array shapes,
  generics, `@throws`, `@author`).
- Be especially restrained in central, frequently read classes (`Protocol`, `StatelessProtocol`, the transports,
  `RequestContext`, `ClientGateway`, `Schema\*` value objects): every comment there is read by every contributor and
  must keep earning its place.
- When editing existing code, don't add comments to explain your own change, and remove comments your change made
  obsolete.

## Pull Request Comments

- Keep comments and PR descriptions short: one to three sentences.
- After a push, say what changed and why. Do not list the checks, tests, assertions or PHP versions you ran, CI shows
  that.
- Do not report that nothing happened, such as "no outstanding review conversations".
- Reply to a review comment only to answer it or to say how it was resolved.
- Do not post a comment for a rebase or force-push unless it changes the code under review.
