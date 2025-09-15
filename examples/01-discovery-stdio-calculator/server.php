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

logger()->info('Starting MCP Stdio Calculator Server...');

Server::make()
    ->setServerInfo('Stdio Calculator', '1.1.0', 'Basic Calculator over STDIO transport.')
    ->setContainer(container())
    ->setLogger(logger())
    ->setDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new StdioTransport(logger: logger()));

logger()->info('Server listener stopped gracefully.');
