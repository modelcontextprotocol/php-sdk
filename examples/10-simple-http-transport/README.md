# HTTP MCP Server Example

This example demonstrates how to use the MCP SDK with HTTP transport using the StreamableHttpTransport. It provides a complete HTTP-based MCP server that can handle JSON-RPC requests over HTTP POST.

## Usage

**Step 1: Start the HTTP server**

```bash
cd examples/10-simple-http-transport
php -S localhost:8000 server.php
```

**Step 2: Connect with MCP Inspector**

```bash
npx @modelcontextprotocol/inspector http://localhost:8000
```

## Available Features

- **Tools**: `current_time`, `calculate`
- **Resources**: `info://server/status`, `config://app/settings`
- **Prompts**: `greet`
