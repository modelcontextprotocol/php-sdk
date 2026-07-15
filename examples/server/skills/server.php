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

logger()->info('Starting MCP Skills Example Server...');

$server = Server::builder()
    ->setServerInfo('MCP Skills Example', '1.0.0')
    ->setLogger(logger())
    ->addSkillsFromDirectory(__DIR__.'/skills')
    ->build();

$result = $server->run(transport());

logger()->info('Server stopped gracefully.', ['result' => $result]);

shutdown($result);
