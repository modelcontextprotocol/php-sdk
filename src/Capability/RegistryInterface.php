<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface RegistryInterface
{
    /**
     * Registers a tool with its handler.
     *
     * @param Handler $handler
     */
    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void;

    /**
     * Registers a resource with its handler.
     *
     * @param Handler $handler
     */
    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void;

    /**
     * Registers a resource template with its handler and completion providers.
     *
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void;

    /**
     * Registers a prompt with its handler and completion providers.
     *
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void;

    /**
     * Clear discovered elements from registry.
     */
    public function clear(): void;

    /**
     * Get the current discovery state (only discovered elements, not manual ones).
     */
    public function getDiscoveryState(): DiscoveryState;

    /**
     * Set discovery state, replacing all discovered elements.
     * Manual elements are preserved.
     */
    public function setDiscoveryState(DiscoveryState $state): void;

    /**
     * @return bool true if any tools are registered
     */
    public function hasTools(): bool;

    /**
     * Gets all registered tools.
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets a tool reference by name.
     *
     * @throws ToolNotFoundException
     */
    public function getTool(string $name): ToolReference;

    /**
     * @return bool true if any resources are registered
     */
    public function hasResources(): bool;

    /**
     * Gets all registered resources.
     */
    public function getResources(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets a resource reference by URI (includes template matching if enabled).
     *
     * @throws ResourceNotFoundException
     */
    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference;

    /**
     * @return bool true if any resource templates are registered
     */
    public function hasResourceTemplates(): bool;

    /**
     * Gets all registered resource templates.
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets a resource template reference by URI template.
     *
     * @throws ResourceNotFoundException
     */
    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference;

    /**
     * @return bool true if any prompts are registered
     */
    public function hasPrompts(): bool;

    /**
     * Gets all registered prompts.
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets a prompt reference by name.
     *
     * @throws PromptNotFoundException
     */
    public function getPrompt(string $name): PromptReference;
}
