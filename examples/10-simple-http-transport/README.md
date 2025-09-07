# HTTP MCP Server Example

This example demonstrates how to use the MCP SDK with HTTP transport using the StreamableHttpTransport. It provides a complete HTTP-based MCP server that can handle JSON-RPC requests over HTTP POST.

## Installation

```bash
cd /path/to/your/project/examples/10-simple-http-transport
composer update
```

## Usage

### As HTTP Server

You can use this with any HTTP server or framework that can proxy requests to PHP:

```bash
# Using PHP built-in server (for testing)
php -S localhost:8000

# Or with Apache/Nginx, point your web server to serve this directory
```

### With MCP Inspector

Run with the MCP Inspector for testing:

```bash
npx @modelcontextprotocol/inspector http://localhost:8000
```

## API

The server accepts JSON-RPC 2.0 requests via HTTP POST.

### Available Endpoints

- **Tools**: `current_time`, `calculate`
- **Resources**: `info://server/status`, `config://app/settings`
- **Prompts**: `greet`

### Example Request

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "current_time"}}'
```
