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

use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Page;

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
     *
     * @throws ToolNotFoundException
     */
    public function getTool(string $name): ToolReference;

    /**
     * Gets a resource reference by URI (includes template matching if enabled).
     *
     * @throws ResourceNotFoundException
     */
    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference;

    /**
     * Gets a resource template reference by URI template.
     *
     * @throws ResourceNotFoundException
     */
    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference;

    /**
     * Gets a prompt reference by name.
     *
     * @throws PromptNotFoundException
     */
    public function getPrompt(string $name): PromptReference;

    /**
     * Gets all registered tools.
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resources.
     */
    public function getResources(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered prompts.
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resource templates.
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Checks if any elements (manual or discovered) are currently registered.
     */
    public function hasElements(): bool;
}
