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
use Mcp\Server\Transports\StreamableHttpServerTransport;

logger()->info('Starting MCP Schema Showcase Server...');

Server::make()
    ->withServerInfo('Schema Showcase', '1.0.0')
    ->withLogger(logger())
    ->withDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new StreamableHttpServerTransport('127.0.0.1', 8080, 'mcp'));

logger()->info('Server listener stopped gracefully.');
