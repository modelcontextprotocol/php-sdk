# MCP Server

A framework-agnostic server for the [Model Context Protocol](https://modelcontextprotocol.io): expose tools,
resources and prompts over STDIO or HTTP, declared with attributes or registered on the builder. It serves both the
`initialize` handshake and the stateless `2026-07-28` revision.

```bash
composer require mcp/server
```

Some features need an extra package:

* `symfony/finder` for the file-based discovery of tools, resources and prompts
* `firebase/php-jwt` for validating OAuth access tokens with the `JwtTokenValidator`
* a PSR-6 or PSR-16 cache for the cached discovery and the session stores that use them
* `symfony/lock` for session locks
* PSR-7 and PSR-17 implementations for the HTTP transport, for example `nyholm/psr7`

To build a client as well, install [`mcp/sdk`](https://packagist.org/packages/mcp/sdk) instead.

## Resources

* [Documentation](https://php.sdk.modelcontextprotocol.io/)
* [Changelog](https://github.com/modelcontextprotocol/php-sdk/blob/main/CHANGELOG.md)
* [Report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and
  [send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls)
  in the [main repository](https://github.com/modelcontextprotocol/php-sdk)

This repository is a read-only split of [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).
