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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
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

$listToolsHandler = new class($toolDefinitions) implements RequestHandlerInterface {
    /**
     * @param array<string, Tool> $toolDefinitions
     */
    public function __construct(private array $toolDefinitions)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        assert($request instanceof ListToolsRequest);

        return new Response($request->getId(), new ListToolsResult(array_values($this->toolDefinitions), null));
    }
};

$callToolHandler = new class($toolDefinitions) implements RequestHandlerInterface {
    /**
     * @param array<string, Tool> $toolDefinitions
     */
    public function __construct(private array $toolDefinitions)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        assert($request instanceof CallToolRequest);

        $name = $request->name;
        $args = $request->arguments ?? [];

        if (!isset($this->toolDefinitions[$name])) {
            return new Error($request->getId(), Error::METHOD_NOT_FOUND, sprintf('Tool not found: %s', $name));
        }

        try {
            switch ($name) {
                case 'say_hello':
                    $greetName = (string) ($args['name'] ?? 'world');
                    $result = [new TextContent(sprintf('Hello, %s!', $greetName))];
                    break;
                case 'sum':
                    $a = (float) ($args['a'] ?? 0);
                    $b = (float) ($args['b'] ?? 0);
                    $result = [new TextContent((string) ($a + $b))];
                    break;
                default:
                    $result = [new TextContent('Unknown tool')];
            }

            return new Response($request->getId(), new CallToolResult($result));
        } catch (Throwable $e) {
            return new Response($request->getId(), new CallToolResult([new TextContent('Tool execution failed')], true));
        }
    }
};

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
