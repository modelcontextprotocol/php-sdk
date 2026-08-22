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

## The SDK's own BC promise

The SDK is pre-1.0 and experimental, and the public
API can still change without a deprecation cycle where the spec itself hasn't moved. Once past 1.0, the SDK
follows [Symfony's backward-compatibility promise](https://symfony.com/doc/current/contributing/code/bc.html),
and the twelve-month deprecation window above becomes the
floor for any BC break the SDK introduces on its own, not just ones the spec forces.
