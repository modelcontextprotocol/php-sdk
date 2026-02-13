<?php

/**
 * HTTP Client Example.
 *
 * This example demonstrates how to use the MCP client with an HTTP transport
 * to communicate with a remote MCP server over HTTP.
 *
 * Usage: php examples/client/http_discovery_calculator.php
 *
 * Before running, start an HTTP MCP server:
 *   php -S localhost:8080 examples/server/http-discovery-calculator/server.php
 */

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;

$endpoint = 'http://localhost:8000';

$client = Client::builder()
    ->setClientInfo('HTTP Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->build();

$transport = new HttpTransport($endpoint);

try {
    echo "Connecting to MCP server at {$endpoint}...\n";
    $client->connect($transport);

    echo "Connected! Server info:\n";
    $serverInfo = $client->getServerInfo();
    echo '  Name: '.($serverInfo->name ?? 'unknown')."\n";
    echo '  Version: '.($serverInfo->version ?? 'unknown')."\n\n";

    echo "Available tools:\n";
    $toolsResult = $client->listTools();
    foreach ($toolsResult->tools as $tool) {
        echo "  - {$tool->name}: {$tool->description}\n";
    }
    echo "\n";

    echo "Available resources:\n";
    $resourcesResult = $client->listResources();
    foreach ($resourcesResult->resources as $resource) {
        echo "  - {$resource->uri}: {$resource->name}\n";
    }
    echo "\n";

    echo "Available prompts:\n";
    $promptsResult = $client->listPrompts();
    foreach ($promptsResult->prompts as $prompt) {
        echo "  - {$prompt->name}: {$prompt->description}\n";
    }
    echo "\n";
} catch (Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString()."\n";
} finally {
    echo "Disconnecting...\n";
    $client->disconnect();
    echo "Done.\n";
}
