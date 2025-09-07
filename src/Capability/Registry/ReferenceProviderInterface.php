<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * Interface for providing access to registered MCP elements.
 * Separates the concern of accessing elements from registering them.
 *
 * @author Pavel Buchnev <butschster@gmail.com>
 */
interface ReferenceProviderInterface
{
    /**
     * Gets a tool reference by name.
     */
    public function getTool(string $name): ?ToolReference;

    /**
     * Gets a resource reference by URI (includes template matching if enabled).
     */
    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference|null;

    /**
     * Gets a resource template reference by URI template.
     */
    public function getResourceTemplate(string $uriTemplate): ?ResourceTemplateReference;

    /**
     * Gets a prompt reference by name.
     */
    public function getPrompt(string $name): ?PromptReference;

    /**
     * Gets all registered tools.
     *
     * @return array<string, Tool>
     */
    public function getTools(): array;

    /**
     * Gets all registered resources.
     *
     * @return array<string, Resource>
     */
    public function getResources(): array;

    /**
     * Gets all registered prompts.
     *
     * @return array<string, Prompt>
     */
    public function getPrompts(): array;

    /**
     * Gets all registered resource templates.
     *
     * @return array<string, ResourceTemplate>
     */
    public function getResourceTemplates(): array;

    /**
     * Checks if any elements (manual or discovered) are currently registered.
     */
    public function hasElements(): bool;
}
