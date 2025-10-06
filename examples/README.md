# MCP SDK Examples

This directory contains various examples of how to use the PHP MCP SDK.

You can run the examples with the dependencies already installed in the root directory of the SDK.

For running an example, you execute the `server.php` like this:
```bash
# For examples using STDIO transport
php examples/stdio-discovery-calculator/server.php

# For examples using Streamable HTTP transport
php -S localhost:8000 examples/http-discovery-userprofile/server.php
```

You will see debug outputs to help you understand what is happening.

Run with Inspector:

```bash
npx @modelcontextprotocol/inspector php examples/stdio-discovery-calculator/server.php
```

## Debugging

You can enable debug output by setting the `DEBUG` environment variable to `1`, and additionally log to a file by
setting the `FILE_LOG` environment variable to `1` as well. A `dev.log` file gets written within the example's
directory.

With the Inspector you can set the environment variables like this:
```bash
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/stdio-discovery-calculator/server.php
```
