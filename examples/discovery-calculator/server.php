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
use Mcp\Server\Session\FileSessionStore;

logger()->info('Starting MCP Calculator Server...');

$server = Server::builder()
    ->setServerInfo('Calculator', '1.1.0', 'Basic Calculator')
    ->setInstructions('This server supports basic arithmetic operations: add, subtract, multiply, and divide. Send JSON-RPC requests to perform calculations.')
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger(logger())
    ->setDiscovery(__DIR__)
    ->build();

$result = $server->run(transport());

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

shutdown($result);
