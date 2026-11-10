<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Source;

use Mcp\Server\Capability\Registry\PromptReference;
use Mcp\Server\Capability\Registry\ResourceReference;
use Mcp\Server\Capability\Registry\ResourceTemplateReference;
use Mcp\Server\Capability\Registry\ToolReference;

/**
 * An immutable set of elements. Serializable as long as its handlers are not closures.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ElementCollection
{
    /**
     * @param array<ToolReference>             $tools
     * @param array<ResourceReference>         $resources
     * @param array<ResourceTemplateReference> $resourceTemplates
     * @param array<PromptReference>           $prompts
     */
    public function __construct(
        private readonly array $tools = [],
        private readonly array $resources = [],
        private readonly array $resourceTemplates = [],
        private readonly array $prompts = [],
    ) {
    }

    /**
     * @return array<ToolReference>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<ResourceReference>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @return array<ResourceTemplateReference>
     */
    public function getResourceTemplates(): array
    {
        return $this->resourceTemplates;
    }

    /**
     * @return array<PromptReference>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }
}
