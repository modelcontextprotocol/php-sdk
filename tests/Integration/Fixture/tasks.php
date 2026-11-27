<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Schema\RequestMeta;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$extension = 'io.modelcontextprotocol/tasks';
$extensions = [$extension => new stdClass()];
$task = [
    'taskId' => 'greeting',
    'status' => 'working',
    'statusMessage' => 'Starting greeting',
    'createdAt' => '2026-07-28T12:00:00Z',
    'lastUpdatedAt' => '2026-07-28T12:00:00Z',
    'ttlMs' => null,
    'pollIntervalMs' => 250,
];

$capabilities = ['tools' => new stdClass(), 'extensions' => $extensions];
$serverInfo = ['name' => 'Tasks fixture', 'version' => '1.0.0'];
$inputRequests = (object) [[
    'method' => 'elicitation/create',
    'params' => [
        'mode' => 'form',
        'message' => 'What is your name?',
        'requestedSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
    ],
]];
$states = [];

while (false !== $line = fgets(\STDIN)) {
    $request = json_decode($line, flags: \JSON_THROW_ON_ERROR);
    if (!$request instanceof stdClass || !isset($request->id)) {
        continue;
    }

    $params = $request->params;
    $response = ['jsonrpc' => '2.0', 'id' => $request->id];
    $clientCapabilities = 'initialize' === $request->method
        ? $params->capabilities
        : $params->_meta->{RequestMeta::CLIENT_CAPABILITIES};

    if (!($clientCapabilities->extensions->{$extension} ?? null) instanceof stdClass) {
        $response['error'] = ['code' => -32021, 'message' => 'Expected the tasks capability as a JSON object'];
    } elseif (str_starts_with($request->method, 'tasks/') && !isset($states[$params->taskId])) {
        $response['error'] = ['code' => -32602, 'message' => 'Unknown task'];
    } else {
        $result = [];
        switch ($request->method) {
            case 'initialize':
                $result = ['protocolVersion' => '2025-11-25', 'serverInfo' => $serverInfo, 'capabilities' => $capabilities];
                break;
            case 'server/discover':
                $result = ['supportedVersions' => ['2026-07-28'], 'serverInfo' => $serverInfo, 'capabilities' => $capabilities];
                break;
            case 'tools/call':
            case 'prompts/get':
            case 'resources/read':
                $result = match ($request->method) {
                    'tools/call' => 'immediate' === $params->name ? ['content' => [['type' => 'text', 'text' => 'Immediate']]] : null,
                    'prompts/get' => 'immediate' === $params->name ? ['description' => 'Immediate', 'messages' => []] : null,
                    default => 'test://immediate' === $params->uri ? ['contents' => [['uri' => $params->uri, 'text' => 'Immediate']]] : null,
                };
                if (null === $result) {
                    $taskId = 'fail' === ($params->name ?? null) ? 'failure' : 'greeting';
                    $states[$taskId] = 'failure' === $taskId
                        ? ['status' => 'failed', 'error' => ['code' => -32603, 'message' => 'Task failed']]
                        : ['status' => 'input_required', 'statusMessage' => 'Waiting for a name', 'pollIntervalMs' => 500, 'inputRequests' => $inputRequests];
                    $result = ['resultType' => 'task', ...$task, 'taskId' => $taskId];
                }
                break;
            case 'tasks/get':
                $result = [...$task, 'taskId' => $params->taskId, ...$states[$params->taskId]];
                break;
            case 'tasks/update':
                if (!$params->inputResponses instanceof stdClass || 'accept' !== ($params->inputResponses->{'0'}->action ?? null)) {
                    $response['error'] = ['code' => -32602, 'message' => 'Expected an answer keyed by the outstanding request ID'];
                    break;
                }
                $name = $params->inputResponses->{'0'}->content->name;
                $states[$params->taskId] = [
                    'status' => 'completed',
                    'result' => ['content' => [['type' => 'text', 'text' => 'Hello, '.$name.'!']]],
                ];
                break;
            case 'tasks/cancel':
                $states[$params->taskId] = ['status' => 'cancelled'];
                break;
            default:
                $response['error'] = ['code' => -32601, 'message' => 'Unknown method'];
        }
        if (!isset($response['error'])) {
            $response['result'] = 'initialize' === $request->method ? $result : ['resultType' => 'complete', ...$result];
        }
    }

    fwrite(\STDOUT, json_encode($response, \JSON_THROW_ON_ERROR)."\n");
}
