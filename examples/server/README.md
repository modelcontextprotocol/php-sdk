# MCP SDK Examples

This directory contains various examples of how to use the PHP MCP SDK.

You can run the examples with the dependencies already installed in the root directory of the SDK.
The bootstrapping of the example will choose the used transport based on the SAPI you use.

For running an example, you execute the `server.php` like this:
```bash
# For using the STDIO transport:
php examples/server/discovery-calculator/server.php

# For using the Streamable HTTP transport:
php -S localhost:8000 examples/server/discovery-userprofile/server.php
```

You will see debug outputs to help you understand what is happening.

Run with Inspector:

```bash
npx @modelcontextprotocol/inspector php examples/server/discovery-calculator/server.php
```

## Debugging

You can enable debug output by setting the `DEBUG` environment variable to `1`, and additionally log to a file by
setting the `FILE_LOG` environment variable to `1` as well. A `dev.log` file gets written within the example's
directory.

With the Inspector you can set the environment variables like this:
```bash
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/server/discovery-calculator/server.php
```
