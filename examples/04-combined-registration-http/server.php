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

use Mcp\CombinedHttpExample\Manual\ManualHandlers;
use Mcp\Server;
use Mcp\Server\Transports\HttpServerTransport;

logger()->info('Starting MCP Combined Registration (HTTP) Server...');

Server::make()
    ->withServerInfo('Combined HTTP Server', '1.0.0')
    ->withLogger(logger())
    ->withContainer(container())
    ->withDiscovery(__DIR__, ['.'])
    ->withTool([ManualHandlers::class, 'manualGreeter'])
    ->withResource(
        [ManualHandlers::class, 'getPriorityConfigManual'],
        'config://priority',
        'priority_config_manual',
    )
    ->build()
    ->connect(new HttpServerTransport('127.0.0.1', 8081, 'mcp_combined'));

logger()->info('Server listener stopped gracefully.');
