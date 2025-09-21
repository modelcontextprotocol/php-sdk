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

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Example\CombinedHttpExample\ManualHandlers;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

$request = $creator->fromGlobals();

$server = Server::make()
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

$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);

$server->connect($transport);

$response = $transport->listen();

(new SapiEmitter())->emit($response);
