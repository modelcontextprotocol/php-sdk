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
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\RequestHandler\ReferencePage;

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
     * @param int|null    $limit
     * @param string|null $cursor
     * @return ReferencePage
     */
    public function getTools(?int $limit = null, ?string $cursor = null): ReferencePage;

    /**
     * Gets all registered resources.
     *
     * @param int|null    $limit
     * @param string|null $cursor
     * @return ReferencePage
     */
    public function getResources(?int $limit = null, ?string $cursor = null): ReferencePage;

    /**
     * Gets all registered prompts.
     *
     * @param int|null    $limit
     * @param string|null $cursor
     * @return ReferencePage
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): ReferencePage;

    /**
     * Gets all registered resource templates.
     *
     * @param int|null    $limit
     * @param string|null $cursor
     * @return ReferencePage
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): ReferencePage;

    /**
     * Checks if any elements (manual or discovered) are currently registered.
     */
    public function hasElements(): bool;
}
