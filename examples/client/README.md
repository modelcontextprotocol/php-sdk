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

## Modern-era client (2026-07-28)

Speaks the stateless lifecycle: no `initialize`, a `_meta` envelope and SEP-2243 headers on every
request, and multi round-trip calls answered by the client without the caller noticing.

```bash
# First, start the matching server
php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php

# Then run the client
php examples/client/stateless_lifecycle_client.php
```

## Requirements

All examples require the server examples to be available. The STDIO examples spawn the server process, while the HTTP examples connect to a running HTTP server.
