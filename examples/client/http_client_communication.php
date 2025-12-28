<?php

/**
 * HTTP Client Communication Example
 *
 * This example demonstrates server-to-client communication over HTTP:
 * - Logging notifications
 * - Progress notifications (via SSE streaming)
 * - Sampling requests (mocked LLM response)
 *
 * Usage:
 *   1. Start the server: php -S 127.0.0.1:8000 examples/server/client-communication/server.php
 *   2. Run this script: php examples/client/http_client_communication.php
 *
 * Note: PHP's built-in server works for listing tools, calling tools, and receiving
 * progress/logging notifications. However, sampling requires a concurrent-capable server
 * (e.g., Symfony CLI, PHP-FPM) since the server must process the client's sampling
 * response while the original tool request is still pending. 
 * 
 * Eg. symfony serve --passthru=examples/server/client-communication/server.php --no-tls
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Result\CreateSamplingMessageResult;

$endpoint = 'http://127.0.0.1:8000';

$loggingNotificationHandler = new LoggingNotificationHandler(function (LoggingMessageNotification $n) {
    echo "[LOG {$n->level->value}] {$n->data}\n";
});

$samplingRequestHandler = new SamplingRequestHandler(function (CreateSamplingMessageRequest $request): CreateSamplingMessageResult {
    echo "[SAMPLING] Server requested LLM sampling (max {$request->maxTokens} tokens)\n";

    $mockResponse = "Based on the incident analysis, I recommend: 1) Activate the on-call team, " .
        "2) Isolate affected systems, 3) Begin root cause analysis, 4) Prepare stakeholder communication.";

    return new CreateSamplingMessageResult(
        role: Role::Assistant,
        content: new TextContent($mockResponse),
        model: 'mock-gpt-4',
        stopReason: 'end_turn',
    );
});

$client = Client::builder()
    ->setClientInfo('HTTP Client Communication Test', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(sampling: true))
    ->addNotificationHandler($loggingNotificationHandler)
    ->addRequestHandler($samplingRequestHandler)
    ->build();

$transport = new HttpTransport(endpoint: $endpoint);

try {
    echo "Connecting to MCP server at {$endpoint}...\n";
    $client->connect($transport);

    $serverInfo = $client->getServerInfo();
    echo "Connected to: " . ($serverInfo?->name ?? 'unknown') . "\n\n";

    echo "Available tools:\n";
    $toolsResult = $client->listTools();
    foreach ($toolsResult->tools as $tool) {
        echo "  - {$tool->name}\n";
    }
    echo "\n";

    echo "Calling 'run_dataset_quality_checks'...\n\n";
    $result = $client->callTool(
        name: 'run_dataset_quality_checks',
        arguments: ['dataset' => 'sales_transactions_q4'],
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

    echo "\nCalling 'coordinate_incident_response'...\n\n";
    $result = $client->callTool(
        name: 'coordinate_incident_response',
        arguments: ['incidentTitle' => 'Database connection pool exhausted'],
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
    echo $e->getTraceAsString() . "\n";
} finally {
    $client->disconnect();
}
