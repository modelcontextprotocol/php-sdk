# Client Examples

These examples demonstrate how to use the MCP PHP Client SDK.

## STDIO Client

Connects to an MCP server running as a child process:

```bash
php examples/client/stdio_discovery_calculator.php
```

## HTTP Client

Connects to an MCP server over HTTP:

```bash
# First, start an HTTP server
php -S localhost:8000 examples/server/discovery-calculator/server.php

# Then run the client
php examples/client/http_discovery_calculator.php
```

## Requirements

All examples require the server examples to be available. The STDIO examples spawn the server process, while the HTTP examples connect to a running HTTP server.
