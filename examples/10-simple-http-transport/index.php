<?php

require_once __DIR__ . '/vendor/autoload.php';

use Mcp\Server;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);

$request = $creator->fromGlobals();

$server = Server::make()
    ->withServerInfo('HTTP MCP Server', '1.0.0')
    ->withDiscovery(__DIR__, ['src'])
    ->build();

$transport = new StreamableHttpTransport($request, $psr17Factory, $psr17Factory);

$server->connect($transport);

$response = $transport->listen();

(new SapiEmitter())->emit($response);
