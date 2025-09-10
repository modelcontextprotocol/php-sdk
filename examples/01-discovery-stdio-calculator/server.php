#!/usr/bin/env php
<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Stdio Calculator Server...');

Server::make()
    ->withServerInfo('Stdio Calculator', '1.1.0', 'Basic Calculator over STDIO transport.')
    ->withContainer(container())
    ->withLogger(logger())
    ->withDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new StdioTransport(logger: logger()));

logger()->info('Server listener stopped gracefully.');
