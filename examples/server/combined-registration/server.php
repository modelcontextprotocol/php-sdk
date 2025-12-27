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

use Mcp\Example\Server\CombinedRegistration\ManualHandlers;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

$server = Server::builder()
    ->setServerInfo('Combined HTTP Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__)
    ->addTool([ManualHandlers::class, 'manualGreeter'])
    ->addResource(
        [ManualHandlers::class, 'getPriorityConfigManual'],
        'config://priority',
        'priority_config_manual',
    )
    ->build();

$response = $server->run(transport());

shutdown($response);
