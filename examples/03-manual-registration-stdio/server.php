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

use Mcp\Example\ManualStdioExample\SimpleHandlers;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Manual Registration (Stdio) Server...');

$server = Server::builder()
    ->setServerInfo('Manual Reg Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->addTool([SimpleHandlers::class, 'echoText'], 'echo_text')
    ->addResource([SimpleHandlers::class, 'getAppVersion'], 'app://version', 'application_version', mimeType: 'text/plain')
    ->addPrompt([SimpleHandlers::class, 'greetingPrompt'], 'personalized_greeting')
    ->addResourceTemplate([SimpleHandlers::class, 'getItemDetails'], 'item://{itemId}/details', 'get_item_details', mimeType: 'application/json')
    ->build();

$transport = new StdioTransport(logger: logger());

$server->connect($transport);

$transport->listen();

logger()->info('Server listener stopped gracefully.');
