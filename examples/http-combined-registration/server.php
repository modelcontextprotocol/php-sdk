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

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Example\HttpCombinedRegistration\ManualHandlers;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

$psr17Factory = new Psr17Factory();
$request = $psr17Factory->createServerRequestFromGlobals();

$server = Server::builder()
    ->setServerInfo('Combined HTTP Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setDiscovery(__DIR__, ['.'])
    ->addTool([ManualHandlers::class, 'manualGreeter'])
    ->addResource(
        [ManualHandlers::class, 'getPriorityConfigManual'],
        'config://priority',
        'priority_config_manual',
    )
    ->build();

$transport = new StreamableHttpTransport($request);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
