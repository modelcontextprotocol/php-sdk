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

logger()->info('Starting MCP Stdio Logging Showcase Server...');

// Create server with auto-discovery of MCP capabilities and ENABLE MCP LOGGING
$server = Server::builder()
    ->setServerInfo('Stdio Logging Showcase', '1.0.0', 'Demonstration of auto-injected MCP logging in capability handlers.')
    ->setContainer(container())
    ->setLogger(logger())
    ->enableClientLogging()  // Enable MCP logging capability and auto-injection!
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: logger());

$server->run($transport);

logger()->info('Logging Showcase Server is ready!');
logger()->info('This example demonstrates auto-injection of ClientLogger into capability handlers.');
