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
use Mcp\Server\Transports\HttpServerTransport;

logger()->info('Starting MCP Complex Schema HTTP Server...');

Server::make()
    ->setServerInfo('Event Scheduler Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new HttpServerTransport('127.0.0.1', 8082, 'mcp_scheduler'));

logger()->info('Server listener stopped gracefully.');
