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

$server = Server::builder()
    ->setServerInfo('Stdio Calculator', '1.1.0', 'Basic Calculator over STDIO transport.')
    ->setInstructions('This server supports basic arithmetic operations: add, subtract, multiply, and divide. Send JSON-RPC requests to perform calculations.')
    ->setContainer(container())
    ->setLogger(logger())
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: logger());

$server->connect($transport);

$transport->listen();

logger()->info('Server listener stopped gracefully.');
