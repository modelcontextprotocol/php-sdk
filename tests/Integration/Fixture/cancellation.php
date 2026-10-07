<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// This fixture keeps a tool request pending while it reads the next JSON-RPC line.
// The SDK server's single fiber cannot read cancellations during a blocking tool.
$log = getenv('MCP_FIXTURE_LOG');

while (false !== ($line = fgets(\STDIN))) {
    $message = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
    $method = $message['method'] ?? null;
    $id = $message['id'] ?? null;

    if ('initialize' === $method) {
        echo json_encode([
            'jsonrpc' => '2.0', 'id' => $id,
            'result' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => (object) [],
                'serverInfo' => ['name' => 'cancellation-fixture', 'version' => '1.0.0'],
            ],
        ], \JSON_THROW_ON_ERROR)."\n";
    } elseif ('tools/call' === $method) {
        $name = $message['params']['name'];
        file_put_contents($log, json_encode(['event' => 'call', 'name' => $name, 'id' => $id], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        if ('slow' === $name) {
            if (isset($message['params']['_meta']['progressToken'])) {
                echo json_encode([
                    'jsonrpc' => '2.0', 'method' => 'notifications/progress',
                    'params' => ['progressToken' => $message['params']['_meta']['progressToken'], 'progress' => 1],
                ], \JSON_THROW_ON_ERROR)."\n";
            }
        } else {
            echo json_encode([
                'jsonrpc' => '2.0', 'id' => $id,
                'result' => ['content' => [['type' => 'text', 'text' => 'quick']]],
            ], \JSON_THROW_ON_ERROR)."\n";
        }
    } elseif ('notifications/cancelled' === $method) {
        $abandonedId = $message['params']['requestId'];
        file_put_contents($log, json_encode(['event' => 'cancelled', 'id' => $abandonedId], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
        echo json_encode([
            'jsonrpc' => '2.0', 'id' => $abandonedId,
            'result' => ['content' => [['type' => 'text', 'text' => 'late']]],
        ], \JSON_THROW_ON_ERROR)."\n";
    }
}
