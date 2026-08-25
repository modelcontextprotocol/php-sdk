# Installation

The SDK ships as a single Composer package:

```bash
composer require mcp/sdk
```

It requires **PHP 8.1+** and the `fileinfo` extension. Most of what it pulls in is PSR
interface packages (`psr/container`, `psr/log`, `psr/http-message`, …); the rest is
`opis/json-schema` for schema validation, `symfony/uid` for session identifiers,
`phpdocumentor/reflection-docblock` for reading descriptions out of your docblocks, and
`php-http/discovery` for finding PSR-17/PSR-18 implementations.

That is enough for a complete [STDIO server](../run/stdio.md) and for the
[STDIO client](../client/transports.md).

## Optional packages

Which extras you need depends on what you build:

| You want to… | Also install |
| --- | --- |
| discover `#[McpTool]` & friends from a directory | `symfony/finder` |
| serve over [HTTP](../run/http.md) | any PSR-17 implementation, e.g. `nyholm/psr7` |
| emit the response from a standalone HTTP entry point | `laminas/laminas-httphandlerrunner` |
| [connect a client over HTTP](../client/transports.md) | any PSR-18 client, e.g. `symfony/http-client` |
| [validate JWT access tokens](../run/authorization.md) | `firebase/php-jwt` |
| store sessions in a PSR-16 cache | `psr/simple-cache` implementation, e.g. `symfony/cache` |

PSR-17 and PSR-18 implementations are found through
[`php-http/discovery`](https://docs.php-http.org/en/latest/discovery.html), so
installing the package is all that is needed — no wiring:

```bash
composer require nyholm/psr7
```

If discovery picks the wrong one, or you want to be explicit, pass the factories to the
transport yourself; see [HTTP transport](../run/http.md).

## Next

Write your [first server](first-server.md).
