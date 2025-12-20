# Client Examples

These examples demonstrate how to use the MCP PHP Client SDK.

## STDIO Client

Connects to an MCP server running as a child process:

```bash
php examples/client/stdio_example.php
```

## HTTP Client

Connects to an MCP server over HTTP:

```bash
# First, start an HTTP server
php -S localhost:8080 examples/http-discovery-userprofile/server.php

# Then run the client
php examples/client/http_example.php
```

## Requirements

Both examples require the server examples to be available. The STDIO example spawns the discovery-calculator server, while the HTTP example connects to a running HTTP server.
