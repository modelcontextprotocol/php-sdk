<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace App;

use Mcp\Capability\PromptChain;
use Mcp\Capability\ResourceChain;
use Mcp\Capability\ToolChain;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\NotificationHandler\InitializedHandler;
use Mcp\Server\RequestHandler\CallToolHandler;
use Mcp\Server\RequestHandler\GetPromptHandler;
use Mcp\Server\RequestHandler\InitializeHandler;
use Mcp\Server\RequestHandler\ListPromptsHandler;
use Mcp\Server\RequestHandler\ListResourcesHandler;
use Mcp\Server\RequestHandler\ListToolsHandler;
use Mcp\Server\RequestHandler\PingHandler;
use Mcp\Server\RequestHandler\ReadResourceHandler;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class Builder
{
    /**
     * @return list<MethodHandlerInterface>
     */
    public static function buildMethodHandlers(): array
    {
        $promptManager = new PromptChain([
            new ExamplePrompt(),
        ]);

        $resourceManager = new ResourceChain([
            new ExampleResource(),
        ]);

        $toolManager = new ToolChain([
            new ExampleTool(),
        ]);

        return [
            new InitializedHandler(),
            new InitializeHandler(),
            new PingHandler(),
            new ListPromptsHandler($promptManager),
            new GetPromptHandler($promptManager),
            new ListResourcesHandler($resourceManager),
            new ReadResourceHandler($resourceManager),
            new CallToolHandler($toolManager),
            new ListToolsHandler($toolManager),
        ];
    }
}
