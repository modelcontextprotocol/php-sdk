<?php

/**
 * STDIO Client Communication Example
 *
 * This example demonstrates server-to-client communication (logging and progress
 * notifications) via STDIO transport, with per-request progress callbacks.
 *
 * Usage: php examples/client/stdio_client_communication.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Handler\LoggingNotificationHandler;
use Mcp\Client\Transport\StdioClientTransport;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Notification\LoggingMessageNotification;

$client = Client::builder()
    ->setClientInfo('STDIO Client Communication Test', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->addNotificationHandler(new LoggingNotificationHandler(function (LoggingMessageNotification $n) {
        echo "[LOG {$n->level->value}] {$n->data}\n";
    }))
    ->build();

$transport = new StdioClientTransport(
    command: 'php',
    args: [__DIR__ . '/../server/client-communication/server.php'],
);

try {
    echo "Connecting to MCP server...\n";
    $client->connect($transport);

    $serverInfo = $client->getServerInfo();
    echo "Connected to: " . ($serverInfo['serverInfo']['name'] ?? 'unknown') . "\n\n";

    echo "Available tools:\n";
    $toolsResult = $client->listTools();
    foreach ($toolsResult->tools as $tool) {
        echo "  - {$tool->name}\n";
    }
    echo "\n";

    echo "Calling 'run_dataset_quality_checks'...\n\n";
    $result = $client->callTool(
        name: 'run_dataset_quality_checks',
        arguments: ['dataset' => 'customer_orders_2024'],
        onProgress: function (float $progress, ?float $total, ?string $message) {
            $percent = $total > 0 ? round(($progress / $total) * 100) : '?';
            echo "[PROGRESS {$percent}%] {$message}\n";
        }
    );

    echo "\nResult:\n";
    foreach ($result->content as $content) {
        if ($content instanceof TextContent) {
            echo $content->text . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
} finally {
    $client->disconnect();
}
