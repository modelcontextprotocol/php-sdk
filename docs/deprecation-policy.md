# Deprecation policy

The MCP specification (SEP-2596) defines a formal feature lifecycle: **Active → Deprecated → Removed**,
with a minimum of twelve months between a feature being marked deprecated and its earliest possible removal.
Each stage transition gets its own SEP — deprecating a feature and removing it are separate proposals, never
bundled into one.

The PHP SDK mirrors that window for anything it deprecates, whether the deprecation originates in the spec
or is SDK-internal (an API shape the SDK wants to retire independently of the protocol).

## What a deprecation looks like here

A deprecated element carries a PHP `@deprecated` tag naming the protocol revision (or SDK version, for an
SDK-internal deprecation) that introduced the deprecation and the earliest removal date, twelve months out:

```php
/**
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 */
```

This is already in place for the first real instance of the policy: SEP-2577 deprecated Roots, Sampling and
Logging in the `2026-07-28` revision (merged in #427). Twenty-seven call sites across `src/Schema/`,
`src/Client/` and `src/Server/` carry the tag today — see `RootsListChangedNotification`,
`SetLogLevelRequest`, `LoggingMessageNotification`, `SamplingCallbackInterface`, and others. Each keeps
working exactly as before; the tag is a migration signal, not a behavior change. The CHANGELOG entry for
that release states the same window and points at the replacement for each: tool arguments or resource URIs
instead of roots, a direct LLM provider integration instead of sampling, `stderr`/OpenTelemetry instead of
`notifications/message`.

## The SDK's own BC promise

The SDK is pre-1.0 and experimental — the root `README.md` and `CLAUDE.md` say so plainly, and the public
API can still change without a deprecation cycle where the spec itself hasn't moved. Once past 1.0, the SDK
follows Symfony's backward-compatibility promise, and the twelve-month deprecation window above becomes the
floor for any BC break the SDK introduces on its own, not just ones the spec forces.

## Relationship to the protocol lifecycle

This document covers deprecation as an API-surface concern: what gets tagged, for how long, with what
migration note. It's a different (related) axis from
[protocol era support](protocol-versions.md#what-was-removed) — which protocol revisions the SDK serves at
all, and what a given revision no longer accepts on the wire. A method can be removed from the modern
revision's wire surface while its PHP binding stays present and undeprecated, because a handshake-era client
still needs it; see [Protocol versions](protocol-versions.md)' "What was removed" and "Deprecations"
sections for the current list.
