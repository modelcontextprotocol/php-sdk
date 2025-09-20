<?php

require_once dirname(__DIR__) . '/bootstrap.php';
chdir(__DIR__);

use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Session\FileSessionStore;

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

$request = $creator->fromGlobals();

$server = Server::make()
    ->withServerInfo('HTTP MCP Server', '1.0.0', 'MCP Server over HTTP transport')
    ->withContainer(container())
    ->withSession(new FileSessionStore(__DIR__ . '/sessions'))
    ->withDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);

$server->connect($transport);

$response = $transport->listen();

(new SapiEmitter())->emit($response);
