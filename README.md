# MCP PHP SDK

The official PHP SDK for Model Context Protocol (MCP). It provides a framework-agnostic API for implementing MCP servers in PHP.

> [!IMPORTANT]
> Currently, we are still in the process of merging [Symfony's MCP SDK](https://github.com/symfony/mcp-sdk) and
> [PHP-MCP](https://github.com/php-mcp) components. Not all code paths are fully tested, complete, or this package
> may contain duplicate functionality or dead code.
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
- [x] Bring back PHP-MCP examples
- [ ] Glue handler, registry and reference handlers
- [ ] Revive `ServerBuilder`
- [ ] Revive transports 
  - [ ] Streamable Transport https://github.com/modelcontextprotocol/php-sdk/issues/7
  - [ ] Http/SSE-based Transport https://github.com/modelcontextprotocol/php-sdk/issues/8
- [ ] Support pagination
- [ ] Support Schema validation
- [ ] Support multiple versions of the MCP specification https://github.com/modelcontextprotocol/php-sdk/issues/14
- [ ] (Re-)Implement missing Notification & Request Handlers https://github.com/modelcontextprotocol/php-sdk/issues/9

---

Examples working
- [x] 01-discovery-stdio-calculator
- [ ] 02-discovery-http-userprofile
- [x] 03-manual-registration-stdio
- [ ] 04-combined-registration-http
- [ ] 05-stdio-env-variables
- [ ] 06-custom-dependencies-stdio
- [ ] 07-complex-tool-schema-http
- [ ] 08-schema-showcase-streamable
- [ ] 09-standalone-cli

## Installation

```bash
composer require mcp/sdk
```

Since this package has no tagged releases yet, it is required to extend your `composer.json`:
```json
"minimum-stability": "dev",
"prefer-stable": true
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

Server::make()
    ->withServerInfo('Stdio Calculator', '1.1.0', 'Basic Calculator over STDIO transport.')
    ->withDiscovery(__DIR__, ['.'])
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

## Documentation

- [SDK documentation](doc/index.rst)
- [Model Context Protocol documentation](https://modelcontextprotocol.io)
- [Model Context Protocol specification](https://spec.modelcontextprotocol.io)
- [Officially supported servers](https://github.com/modelcontextprotocol/servers)

## Examples of MCP Tools that use this SDK

- https://github.com/pronskiy/mcp

## Contributing

We are passionate about supporting contributors of all levels of experience and would love to see you get involved in
the project. See the [contributing guide](CONTRIBUTING.md) to get started before you
[report issues](https://github.com/modelcontextprotocol/php-sdk/issues) and
[send pull requests](https://github.com/modelcontextprotocol/php-sdk/pulls).

## Credits
The starting point for this SDK was the [PHP-MCP](https://github.com/php-mcp/server) project, initiated by [Kyrian Obikwelu](https://github.com/CodeWithKyrian). We are grateful for the work done by Kyrian and other contributors to that repository, which created a solid foundation for this SDK.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
