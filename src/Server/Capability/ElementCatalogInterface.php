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

use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * Lists the elements a server offers.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ElementCatalogInterface
{
    /**
     * Gets all registered tools.
     *
     * @return Page<Tool>
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resources.
     *
     * @return Page<ResourceDefinition>
     */
    public function getResources(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered resource templates.
     *
     * @return Page<ResourceTemplate>
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page;

    /**
     * Gets all registered prompts.
     *
     * @return Page<Prompt>
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page;
}
