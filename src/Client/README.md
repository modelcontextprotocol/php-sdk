# MCP Client

A client for the [Model Context Protocol](https://modelcontextprotocol.io): connect to MCP servers over STDIO or HTTP,
call tools, read resources and get prompts. It speaks both the `initialize` handshake and the stateless
`2026-07-28` revision, and authorizes with OAuth.

```bash
composer require mcp/client
```

Some features need an extra package:

* `firebase/php-jwt` for the `private_key_jwt` client authentication
* a PSR-18 HTTP client and PSR-17 factories for the HTTP transport, for example `symfony/http-client` and
  `nyholm/psr7`

To build a server as well, install [`mcp/sdk`](https://packagist.org/packages/mcp/sdk) instead.

## Resources

* [Documentation](https://php.sdk.modelcontextprotocol.io/)
* [Changelog](https://github.com/modelcontextprotocol/php-sdk/blob/main/CHANGELOG.md)
* [Report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and
  [send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls)
  in the [main repository](https://github.com/modelcontextprotocol/php-sdk)

This repository is a read-only split of [`mcp/sdk`](https://github.com/modelcontextprotocol/php-sdk).
