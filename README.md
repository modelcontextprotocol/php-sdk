# MCP PHP SDK

The official PHP SDK for Model Context Protocol (MCP). It provides a framework-agnostic API for implementing MCP servers
and clients in PHP.

> [!IMPORTANT]
> Currently, the SDK is still in active development and not all components of the MCP specification are implemented.
> 
> If you want to help us stabilize the SDK, please see the
> [issue tracker](https://github.com/modelcontextprotocol/php-sdk/issues).

This project is a collaboration between [the PHP Foundation](https://thephp.foundation/) and the
[Symfony project](https://symfony.com/). It adopts development practices and standards from the Symfony project,
including [Coding Standards](https://symfony.com/doc/current/contributing/code/standards.html) and the
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Until the first major release, this SDK is considered
[experimental](https://symfony.com/doc/current/contributing/code/experimental.html).

## 🚧 Roadmap

Features
- [ ] Stabilize server component with all needed handlers and functional tests
- [ ] Extend documentation, including integration pointer for popular frameworks
- [ ] Implement Client component
- [ ] Support multiple schema versions

## Installation

```bash
composer require mcp/sdk
```

## ⚡ Quick Start: Stdio Server with Discovery

This example demonstrates the most common usage pattern - a `stdio` server using attribute discovery.

**1. Define Your MCP Elements**

Create `src/CalculatorElements.php`:

```php
<?php

namespace App;

use Mcp\Capability\Attribute\McpTool;

class CalculatorElements
{
    #[McpTool(name: 'add_numbers')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}
```

**2. Create the Server Script**

Create `mcp-server.php`:

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

Server::builder()
    ->setServerInfo('Stdio Calculator', '1.1.0', 'Basic Calculator over STDIO transport.')
    ->setDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new StdioTransport());
```

**3. Configure Your MCP Client**

Add to your client configuration (e.g., `mcp.json`):

```json
{
    "mcpServers": {
        "php-calculator": {
            "command": "php",
            "args": ["/absolute/path/to/your/mcp-server.php"]
        }
    }
}
```

**4. Test the Server**

Your AI assistant can now call:
- `add_numbers` - Add two integers

For more examples, see the [examples directory](examples).

## Documentation

- [SDK documentation](docs/index.md)
- [Model Context Protocol documentation](https://modelcontextprotocol.io)
- [Model Context Protocol specification](https://spec.modelcontextprotocol.io)
- [Officially supported servers](https://github.com/modelcontextprotocol/servers)

## PHP libraries using the MCP SDK

* https://github.com/pronskiy/mcp - additional DX layer
* https://github.com/symfony/mcp-bundle - Symfony integration bundle

## Contributing

We are passionate about supporting contributors of all levels of experience and would love to see you get involved in
the project. See the [contributing guide](CONTRIBUTING.md) to get started before you
[report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and
[send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls).

## Credits
Starting point for this SDK was the [PHP-MCP](https://github.com/php-mcp/server) project, initiated by
[Kyrian Obikwelu](https://github.com/CodeWithKyrian), and the [Symfony AI initiative](https://github.com/symfony/ai).
We are grateful for the work done by both projects and their contributors, which created a solid foundation for this SDK.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
