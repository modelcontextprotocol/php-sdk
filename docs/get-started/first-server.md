# First server

A server is a plain PHP class plus three lines of wiring. Create `server.php` next to
your `vendor/` directory:

```php-file title="server.php"
#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';

use Mcp\Server\Capability\Attribute\McpResource;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Server;
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

Discovery needs `symfony/finder`:

```bash
composer require symfony/finder
```

## What each piece does

`#[McpTool]` marks a method as an action the *model* can call. Its name defaults to the
method name, its description comes from the docblock (the summary, plus the longer
description if you write one), and its input schema is
generated from the parameter types — `int $a, int $b` becomes a JSON Schema with two
required integers. See [Tools](../servers/tools.md).

`#[McpResource]` marks a method as read-only data the *application* can read, addressed
by URI. See [Resources](../servers/resources.md).

`setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])` scans those directories for
attributed classes. Scanning is lazy: it happens on the first request that needs the
registry, not when `build()` returns — call `setLazyLoading(false)` if you would rather
pay for it up front. Excluding `vendor` matters because the scan is recursive and would
otherwise read and autoload every file your dependencies ship. If you would rather
register elements explicitly — or mix both — see
[Registering elements](../servers/registration.md).

`run(new StdioTransport())` speaks JSON-RPC over stdin/stdout and returns an exit code.
That is the transport local MCP hosts launch as a subprocess; for a web-facing server
use the [HTTP transport](../run/http.md) instead.

!!! warning "Never write to STDOUT"
    With the STDIO transport, `STDOUT` carries the protocol. `echo`, `print_r()`, or a
    stray `var_dump()` in a handler corrupts the stream. Write to `STDERR`, or use the
    [logger](../handlers/logging.md).

## Run it

```bash
php server.php
```

Nothing happens — the server is waiting for JSON-RPC on stdin, which is exactly right.
Stop it with `Ctrl+C`, and let a real client drive it instead:
[Try it with the Inspector](inspector.md).
