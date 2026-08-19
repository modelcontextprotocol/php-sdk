# MCP PHP SDK

The **Model Context Protocol (MCP)** lets applications provide context to LLMs in a
standardized way, separating the concern of *providing* context from the LLM
interaction itself.

This is the official PHP SDK for it, a collaboration between
[the PHP Foundation](https://thephp.foundation/) and the
[Symfony project](https://symfony.com/). With it you can:

* **Build MCP servers** that expose tools, resources, and prompts to any MCP host.
* **Build MCP clients** that connect to any MCP server.
* Speak both standard transports: STDIO and Streamable HTTP.

!!! warning "Experimental until 1.0"
    This SDK is [experimental](https://symfony.com/doc/current/contributing/code/experimental.html)
    until the first major release; see the
    [roadmap](https://github.com/modelcontextprotocol/php-sdk/blob/main/ROADMAP.md)
    for what is planned next.

## Requirements

PHP 8.1+.

## Installation

```bash
composer require mcp/sdk
```

See [Installation](get-started/installation.md) for the optional PSR packages an HTTP
server or client needs.

## Example

Create a file `server.php`:

```php-file title="server.php"
<?php

require __DIR__.'/vendor/autoload.php';

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

Server::builder()
    ->setServerInfo('Calculator', '1.0.0')
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])
    ->build()
    ->run(new StdioTransport());
```

That's a complete MCP server. It exposes one **tool**, `add`, and one **resource**,
`config://calculator/settings`.

Attribute discovery needs `symfony/finder` (`composer require symfony/finder`). Without
it the server still starts, but discovers nothing and only logs a warning.

Look at what you did *not* write: no JSON Schema — `int $a, int $b` *is* the schema —
no request parsing, no serialization, no protocol handling. You wrote a PHP class with
type hints and a docblock; the SDK does the rest.

[First server](get-started/first-server.md) walks through running it, and
[Try it with the Inspector](get-started/inspector.md) opens it in a UI you can click
around in.

## Where to go next

* **[Get started](get-started/index.md)** takes you from `composer require` to a server
  a real MCP host can talk to.
* What a server exposes — tools, resources, prompts — is **[Servers](servers/index.md)**.
* Getting it in front of clients (STDIO, HTTP, your existing Symfony or Laravel app) is
  **[Running your server](run/index.md)**.
* What is available *inside* the functions you register is
  **[Inside your handler](handlers/index.md)**.
* Building the other side, an application that *uses* MCP servers, is
  **[Clients](client/index.md)**.
* The two protocol eras, and what revision `2026-07-28` changed, are
  **[Protocol versions](protocol-versions.md)**.
* Complete, runnable projects are in **[Examples](examples.md)**.
* Hunting for an exact signature? The **[API Reference](https://php.sdk.modelcontextprotocol.io/api/)**
  is generated from the source.
