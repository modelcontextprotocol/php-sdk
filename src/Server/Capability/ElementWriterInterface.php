<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability;

use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\Capability\Registry\ElementReference;
use Mcp\Server\Capability\Registry\PromptReference;
use Mcp\Server\Capability\Registry\ResourceReference;
use Mcp\Server\Capability\Registry\ResourceTemplateReference;
use Mcp\Server\Capability\Registry\ToolReference;

/**
 * Adds and removes elements.
 *
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ElementWriterInterface
{
    /**
     * Registers a tool with its handler. Overwrites any prior registration of the same name.
     * Returns the stored reference, whose identity callers may track to detect later overwrites.
     *
     * @param Handler $handler
     */
    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference;

    /**
     * Registers a resource with its handler. Overwrites any prior registration of the same URI.
     * Returns the stored reference, whose identity callers may track to detect later overwrites.
     *
     * @param Handler $handler
     */
    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference;

    /**
     * Registers a resource template with its handler and completion providers.
     * Overwrites any prior registration of the same URI template.
     * Returns the stored reference, whose identity callers may track to detect later overwrites.
     *
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference;

    /**
     * Registers a prompt with its handler and completion providers.
     * Overwrites any prior registration of the same name.
     * Returns the stored reference, whose identity callers may track to detect later overwrites.
     *
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference;

    /**
     * Removes a tool by name. No-op if absent.
     */
    public function unregisterTool(string $name): void;

    /**
     * Removes a resource by URI. No-op if absent.
     */
    public function unregisterResource(string $uri): void;

    /**
     * Removes a resource template by URI template. No-op if absent.
     */
    public function unregisterResourceTemplate(string $uriTemplate): void;

    /**
     * Removes a prompt by name. No-op if absent.
     */
    public function unregisterPrompt(string $name): void;
}
