# Deprecation policy

The MCP specification (SEP-2596) defines a formal feature lifecycle: **Active → Deprecated → Removed**,
with a minimum of twelve months between a feature being marked deprecated and its earliest possible removal.
Deprecating a feature needs a SEP. Once the window has elapsed, the Core Maintainers decide on the removal,
which needs no SEP of its own.

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

The deprecated `ClientGateway` methods (`log()`, `sample()` and `listRoots()`) and the client's handlers for
logging, sampling and roots also call `trigger_deprecation()`. This raises a silenced `E_USER_DEPRECATED`
error, which your error handler or test tooling can report. Schema classes only carry the tag.

## The SDK's own BC promise

The SDK is pre-1.0 and experimental, and the public
API can still change without a deprecation cycle where the spec itself hasn't moved. Once past 1.0, the SDK
follows [Symfony's backward-compatibility promise](https://symfony.com/doc/current/contributing/code/bc.html),
and the twelve-month deprecation window above becomes the
floor for any BC break the SDK introduces on its own, not just ones the spec forces.
