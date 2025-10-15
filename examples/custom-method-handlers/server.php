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

use Mcp\Example\CustomMethodHandlers\CallToolRequestHandler;
use Mcp\Example\CustomMethodHandlers\ListToolsRequestHandler;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Custom Method Handlers (Stdio) Server...');

$toolDefinitions = [
    'say_hello' => new Tool(
        name: 'say_hello',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name to greet'],
            ],
            'required' => ['name'],
        ],
        description: 'Greets a user by name.',
        annotations: null,
    ),
    'sum' => new Tool(
        name: 'sum',
        inputSchema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number'],
                'b' => ['type' => 'number'],
            ],
            'required' => ['a', 'b'],
        ],
        description: 'Returns a+b.',
        annotations: null,
    ),
];

$listToolsHandler = new ListToolsRequestHandler($toolDefinitions);
$callToolHandler = new CallToolRequestHandler($toolDefinitions);
$capabilities = new ServerCapabilities(tools: true, resources: false, prompts: false);

$server = Server::builder()
    ->setServerInfo('Custom Handlers Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setCapabilities($capabilities)
    ->addRequestHandlers([$listToolsHandler, $callToolHandler])
    ->build();

$transport = new StdioTransport(logger: logger());

$result = $server->run($transport);

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

exit($result);
