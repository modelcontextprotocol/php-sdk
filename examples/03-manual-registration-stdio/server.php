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

use Mcp\Example\ManualStdioExample\SimpleHandlers;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Manual Registration (Stdio) Server...');

Server::make()
    ->withServerInfo('Manual Reg Server', '1.0.0')
    ->withLogger(logger())
    ->withContainer(container())
    ->withTool([SimpleHandlers::class, 'echoText'], 'echo_text')
    ->withResource([SimpleHandlers::class, 'getAppVersion'], 'app://version', 'application_version', mimeType: 'text/plain')
    ->withPrompt([SimpleHandlers::class, 'greetingPrompt'], 'personalized_greeting')
    ->withResourceTemplate([SimpleHandlers::class, 'getItemDetails'], 'item://{itemId}/details', 'get_item_details', mimeType: 'application/json')
    ->build()
    ->connect(new StdioTransport(logger: logger()));

logger()->info('Server listener stopped gracefully.');
