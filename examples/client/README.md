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

## OAuth client

Connects to a server that requires authorization. The client starts with nothing: the
server's `401` is what triggers discovery, registration, the browser, and the token.

```bash
# First, start the Keycloak-protected server example
cd examples/server/oauth-keycloak && docker compose up -d

# Then run the client, and sign in as demo / demo123 when the browser opens
php examples/client/oauth_client.php
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

## Server-initiated requests

The remaining examples answer requests the server sends back during a call:

```bash
# Sampling, log and progress messages (also available over HTTP)
php examples/client/stdio_client_communication.php

# Elicitation: answering the server's questions from an interactive prompt
php examples/client/stdio_elicitation.php

# Roots: exposing workspace folders and signalling roots/list_changed
php examples/client/stdio_roots.php
```

> **Note**: `http_client_communication.php` needs a server that can answer a second request
> mid-call; PHP's built-in web server only does that with worker processes, e.g.
> `PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8000 …`.

## Requirements

All examples require the server examples to be available. The STDIO examples spawn the server process, while the HTTP examples connect to a running HTTP server.
