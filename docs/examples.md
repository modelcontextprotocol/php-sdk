# Examples

Every example lives in [`examples/`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples)
and runs against the dependencies installed in the repository root. Each server example is a
`server.php` whose bootstrap picks the transport from the SAPI it runs under:

```bash
# STDIO transport
php examples/server/discovery-calculator/server.php

# Streamable HTTP transport
php -S 127.0.0.1:8000 examples/server/discovery-userprofile/server.php

# Interactive testing with the MCP Inspector
npx @modelcontextprotocol/inspector php examples/server/discovery-calculator/server.php
```

## Server examples

| Example | What it shows | Docs |
| --- | --- | --- |
| [`discovery-calculator`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/discovery-calculator) | Attribute discovery of tools, resources and prompts; `ResourceLink` content | [Tools](servers/tools.md) |
| [`discovery-userprofile`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/discovery-userprofile) | Resource templates with completion providers; `FileSessionStore` | [Resource templates](servers/resource-templates.md), [Completions](servers/completions.md) |
| [`explicit-registration`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/explicit-registration) | Manual `addTool()`/`addResource()`/`addResourceTemplate()`/`addPrompt()` without discovery | [Registering elements](servers/registration.md) |
| [`combined-registration`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/combined-registration) | Discovery and manual registration combined, and which wins on conflict | [Registering elements](servers/registration.md) |
| [`cached-discovery`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/cached-discovery) | Caching the discovery scan in a PSR-16 cache | [Server builder](run/server-builder.md#discovery-configuration) |
| [`custom-dependencies`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/custom-dependencies) | Handlers pulling services from a PSR-11 container | [Server builder](run/server-builder.md#service-dependencies) |
| [`complex-tool-schema`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/complex-tool-schema) | Rich input schemas from typed parameters, PHP enums and defaults | [Schema generation](servers/schemas.md) |
| [`schema-showcase`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/schema-showcase) | `#[Schema]` constraint attributes: formats, patterns, ranges | [Schema generation](servers/schemas.md) |
| [`env-variables`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/env-variables) | Configuring a server through environment variables | [Server builder](run/server-builder.md) |
| [`client-communication`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/client-communication) | Sampling, roots, progress and log messages from inside a handler | [Talking back to the client](handlers/client-communication.md) |
| [`client-logging`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/client-logging) | Structured log notifications through the `ClientLogger` | [Logging](handlers/logging.md) |
| [`elicitation`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/elicitation) | Asking the user for input mid-call with `ClientGateway::elicit()` and typed elicitation schemas, on either protocol era | [Asking for input](handlers/input-required.md) |
| [`custom-method-handlers`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/custom-method-handlers) | Registering handlers for custom JSON-RPC methods | [Custom message handlers](advanced/custom-handlers.md) |
| [`mcp-apps`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/mcp-apps) | The MCP Apps extension: a tool that ships an interactive HTML view | [Protocol extensions](advanced/extensions.md) |
| [`stateless-lifecycle`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/stateless-lifecycle) | Revision `2026-07-28`: cache policy, request state, notification bus | [Serving both eras](run/protocol-eras.md), [Caching](run/caching.md), [Subscriptions](run/subscriptions.md) |
| [`oauth-keycloak`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/oauth-keycloak) | OAuth authorization against a Keycloak instance (own README) | [Authorization](run/authorization.md) |
| [`oauth-microsoft`](https://github.com/modelcontextprotocol/php-sdk/tree/main/examples/server/oauth-microsoft) | OAuth authorization against Microsoft Entra ID (own README) | [Authorization](run/authorization.md) |

## Client examples

| Example | What it shows | Docs |
| --- | --- | --- |
| [`stdio_discovery_calculator.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_discovery_calculator.php) | Connecting over STDIO, listing and calling tools, reading resources | [Connecting to a server](client/connecting.md) |
| [`http_discovery_calculator.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/http_discovery_calculator.php) | The same conversation over the Streamable HTTP transport | [Transports](client/transports.md) |
| [`stdio_client_communication.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_client_communication.php) | Answering server-initiated sampling, log and progress messages | [Server-initiated requests](client/server-requests.md) |
| [`http_client_communication.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/http_client_communication.php) | The same handlers over HTTP — see the note on PHP's built-in server below | [Server-initiated requests](client/server-requests.md) |
| [`stdio_elicitation.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_elicitation.php) | Answering elicitation requests from an interactive prompt | [Server-initiated requests](client/server-requests.md) |
| [`stdio_roots.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stdio_roots.php) | Exposing workspace roots and signalling `roots/list_changed` | [Server-initiated requests](client/server-requests.md) |
| [`stateless_lifecycle_client.php`](https://github.com/modelcontextprotocol/php-sdk/blob/main/examples/client/stateless_lifecycle_client.php) | A client pinned to revision `2026-07-28` — see [Modern-era client](#modern-era-client) | [Connecting to a server](client/connecting.md) |

Client examples run directly:

```bash
php examples/client/stdio_discovery_calculator.php
```

> **Note**: PHP's built-in development server handles one request at a time, so the sampling
> round-trip in `http_client_communication.php` will not complete under a plain `php -S`.
> Start it with worker processes instead: `PHP_CLI_SERVER_WORKERS=2 php -S 127.0.0.1:8000 …`.

## The 2026-07-28 lifecycle

`stateless-lifecycle/server.php` answers both eras on the same URL. On revision `2026-07-28`
every request carries its own protocol version and client capabilities, so a call is a single
POST with no handshake before it:

```bash
php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php
```

```bash
curl -sS http://127.0.0.1:8000/ \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: server/discover' \
  -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{
        "io.modelcontextprotocol/protocolVersion":"2026-07-28",
        "io.modelcontextprotocol/clientCapabilities":{}}}}'
```

The example's header comments document the equivalent handshake-era conversation against the
same endpoint. [Serving both eras](run/protocol-eras.md) explains how the routing works.

## Modern-era client

`stateless_lifecycle_client.php` drives the server above from PHP: it pins
`ProtocolVersion::V2026_07_28`, skips the handshake, discovers the server through
`server/discover` and calls a tool — one process, no session:

```bash
php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php &
php examples/client/stateless_lifecycle_client.php
```

See [Clients on the modern revision](client/connecting.md) for the API it uses.
