# MCP PHP SDK

<div align="center">

[![Latest Version](https://img.shields.io/packagist/v/mcp/sdk.svg)](https://packagist.org/packages/mcp/sdk)
[![CI](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/pipeline.yaml/badge.svg)](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/pipeline.yaml)
[![PHP Version](https://img.shields.io/packagist/php-v/mcp/sdk.svg)](https://packagist.org/packages/mcp/sdk)
[![License](https://img.shields.io/packagist/l/mcp/sdk.svg)](LICENSE)

[![Server Conformance 2025-11-25](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/modelcontextprotocol/php-sdk/badges/server-conformance-2025-11-25.json)](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/conformance-weekly.yaml)
[![Client Conformance 2025-11-25](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/modelcontextprotocol/php-sdk/badges/client-conformance-2025-11-25.json)](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/conformance-weekly.yaml)
[![Server Conformance 2026-07-28](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/modelcontextprotocol/php-sdk/badges/server-conformance-2026-07-28.json)](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/conformance-weekly.yaml)
[![Client Conformance 2026-07-28](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/modelcontextprotocol/php-sdk/badges/client-conformance-2026-07-28.json)](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/conformance-weekly.yaml)

</div>

The official PHP SDK for the Model Context Protocol (MCP). It provides a framework-agnostic API for implementing MCP
servers and clients in PHP — tools, resources, prompts, STDIO and HTTP transports, sessions, authorization, and both
protocol eras (the `initialize` handshake and the stateless `2026-07-28` revision).

This project represents a collaboration between [the PHP Foundation](https://thephp.foundation/) and the [Symfony project](https://symfony.com/). It adopts
development practices and standards from the Symfony project, including [Coding Standards](https://symfony.com/doc/current/contributing/code/standards.html) and the
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Until the first major release, this SDK is considered [experimental](https://symfony.com/doc/current/contributing/code/experimental.html), please see the [roadmap](./ROADMAP.md) for
planned next steps and features.

## Installation

```bash
composer require mcp/sdk
```

## Build a server

A server is a plain PHP class plus three lines of wiring:

```php
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

class Calculator
{
    /**
     * Adds two numbers.
     */
    #[McpTool]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[McpResource(uri: 'config://calculator/settings')]
    public function settings(): array
    {
        return ['precision' => 2];
    }
}

exit(Server::builder()
    ->setServerInfo('Calculator', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->build()
    ->run(new StdioTransport()));
```

The walkthrough in [First server](docs/get-started/first-server.md) explains each piece, and
[Try it with the Inspector](docs/get-started/inspector.md) shows it running.

## Build a client

```php
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;

$client = Client::builder()
    ->setClientInfo('My Application', '1.0.0')
    ->build();

$client->connect(new StdioTransport(command: 'php', args: ['/path/to/server.php']));

$tools = $client->listTools();
$result = $client->callTool('add', ['a' => 5, 'b' => 3]);

$client->disconnect();
```

See [Connecting to a server](docs/client/connecting.md) for transports, timeouts, and the
handlers that answer server-initiated requests.

## Documentation

The full documentation is published at **[php.sdk.modelcontextprotocol.io](https://php.sdk.modelcontextprotocol.io/)**.

- **[Get started](docs/get-started/index.md)** — Install the SDK and build your first server
- **[Servers](docs/servers/index.md)** — Tools, resources, resource templates, prompts, and how to register them
- **[Inside your handler](docs/handlers/index.md)** — Talking back to the client, logging, and asking for input
- **[Running your server](docs/run/index.md)** — Server builder, STDIO and HTTP transports, framework integration, sessions, authorization
- **[Clients](docs/client/index.md)** — Client SDK for connecting to and communicating with MCP servers
- **[Protocol versions](docs/protocol-versions.md)** — The two protocol eras, and what revision `2026-07-28` changed
- **[Advanced](docs/advanced/index.md)** — Events, protocol extensions (including MCP Apps), and custom message handlers
- **[Examples](docs/examples.md)** — Runnable server and client examples
- **[API Reference](https://php.sdk.modelcontextprotocol.io/api/)** — Generated class reference

## External Resources

- **[Model Context Protocol Documentation](https://modelcontextprotocol.io)** — Official MCP documentation
- **[Model Context Protocol Specification](https://spec.modelcontextprotocol.io)** — Protocol specification
- **[Officially Supported Servers](https://github.com/modelcontextprotocol/servers)** — Reference server implementations

## PHP Libraries Using the MCP SDK

- [api-platform/mcp](https://github.com/api-platform/mcp) — MCP integration for API Platform
- [bnomei/kirby-mcp](https://github.com/bnomei/kirby-mcp) — MCP server for the Kirby CMS
- [drupal/mcp_server](https://www.drupal.org/project/mcp_server) — MCP server for Drupal exposing configuration and entities as MCP elements
- [josbeir/cakephp-synapse](https://github.com/josbeir/cakephp-synapse) — CakePHP plugin exposing application functionality over MCP
- [nette/mcp-inspector](https://github.com/nette/mcp-inspector) — MCP server for introspecting Nette applications
- [symfony/ai-mate](https://github.com/symfony/ai-mate) — AI development assistant MCP server for Symfony projects
- [symfony/mcp-bundle](https://github.com/symfony/mcp-bundle) — Symfony integration bundle

Building something on top of the SDK? Open a pull request to add it to this list.

## Contributing

We are passionate about supporting contributors of all levels of experience and would love to see you get involved in
the project. Start by [reporting issues](https://github.com/modelcontextprotocol/php-sdk/issues) or
[sending pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls).

## Credits

The starting point for this SDK was the [PHP-MCP](https://github.com/php-mcp/server) project, initiated by [Kyrian Obikwelu](https://github.com/CodeWithKyrian), and the [Symfony AI initiative](https://github.com/symfony/ai). We are grateful for the work done by both projects and their contributors, which created a solid foundation for this SDK.

## License

This project is licensed under the Apache License, Version 2.0 for new contributions, with existing code under the MIT License — see the [LICENSE](LICENSE) file for details.
