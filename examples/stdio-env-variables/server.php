#!/usr/bin/env php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

/*
    |--------------------------------------------------------------------------
    | MCP Stdio Environment Variable Example Server
    |--------------------------------------------------------------------------
    |
    | This server demonstrates how to use environment variables to modify tool
    | behavior. The MCP client can set the APP_MODE environment variable to
    | control the server's behavior.
    |
    | Configure your MCP Client (eg. Cursor) for this server like this:
    |
    | {
    |     "mcpServers": {
    |         "my-php-env-server": {
    |             "command": "php",
    |             "args": ["/full/path/to/examples/05-stdio-env-variables/server.php"],
    |             "env": {
    |                 "APP_MODE": "debug" // or "production", or leave it out
    |             }
    |         }
    |     }
    | }
    |
    | The server will read the APP_MODE environment variable and use it to
    | modify the behavior of the tools.
    |
    | If the APP_MODE environment variable is not set, the server will use the
    | default behavior.
    |
*/

logger()->info('Starting MCP Stdio Environment Variable Example Server...');

$server = Server::builder()
    ->setServerInfo('Env Var Server', '1.0.0')
    ->setLogger(logger())
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: logger());

$result = $server->run($transport);

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

exit($result);
