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

$server = Server::builder()
    ->setServerInfo('Client Logging', '1.0.0', 'Demonstration of MCP logging in capability handlers.')
    ->setContainer(container())
    ->setLogger(logger())
    ->setDiscovery(__DIR__)
    ->build();

$result = $server->run(transport());

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

shutdown($result);
