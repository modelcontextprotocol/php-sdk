<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\CustomMethodHandlers;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/** @implements RequestHandlerInterface<CallToolResult> */
class CallToolRequestHandler implements RequestHandlerInterface
{
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

    /**
     * @return Response<CallToolResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $name = $request->name;
        $args = $request->arguments ?? [];

        if (!isset($this->toolDefinitions[$name])) {
            return new Error($request->getId(), Error::METHOD_NOT_FOUND, \sprintf('Tool not found: %s', $name));
        }

        try {
            switch ($name) {
                case 'say_hello':
                    $greetName = (string) ($args['name'] ?? 'world');
                    $result = [new TextContent(\sprintf('Hello, %s!', $greetName))];
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
        } catch (\Throwable $e) {
            return new Response($request->getId(), new CallToolResult([new TextContent('Tool execution failed')], true));
        }
    }
}
