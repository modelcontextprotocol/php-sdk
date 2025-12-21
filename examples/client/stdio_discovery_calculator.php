<?php

/**
 * Simple STDIO Client Example
 *
 * This example demonstrates how to use the MCP client with a STDIO transport
 * to communicate with an MCP server running as a child process.
 *
 * Usage: php examples/client/stdio_discovery_calculator.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Transport\StdioClientTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\TextResourceContents;

$client = Client::builder()
    ->setClientInfo('STDIO Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->build();

$transport = new StdioClientTransport(
    command: 'php',
    args: [__DIR__ . '/../server/discovery-calculator/server.php'],
);

try {
    echo "Connecting to MCP server...\n";
    $client->connect($transport);

    echo "Connected! Server info:\n";
    $serverInfo = $client->getServerInfo();
    echo "  Name: " . ($serverInfo?->name ?? 'unknown') . "\n";
    echo "  Version: " . ($serverInfo?->version ?? 'unknown') . "\n\n";

    echo "Available tools:\n";
    $toolsResult = $client->listTools();
    foreach ($toolsResult->tools as $tool) {
        echo "  - {$tool->name}: {$tool->description}\n";
    }
    echo "\n";

    echo "Calling 'calculate' tool with a=5, b=3, operation='add'...\n";
    $result = $client->callTool('calculate', ['a' => 5, 'b' => 3, 'operation' => 'add']);
    echo "Result: ";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text;
        }
    }
    echo "\n\n";

    echo "Available resources:\n";
    $resourcesResult = $client->listResources();
    foreach ($resourcesResult->resources as $resource) {
        echo "  - {$resource->uri}: {$resource->name}\n";
    }
    echo "\n";

    echo "Reading resource 'config://calculator/settings'...\n";
    $resourceContent = $client->readResource('config://calculator/settings');
    foreach ($resourceContent->contents as $content) {
        if ($content instanceof TextResourceContents) {
            echo "  Content: " . $content->text . "\n";
            echo "  Mimetype: " . $content->mimeType . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    echo "Disconnecting...\n";
    $client->disconnect();
    echo "Done.\n";
}
