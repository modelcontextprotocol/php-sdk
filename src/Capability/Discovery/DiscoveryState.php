<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;

/**
 * Represents the state of discovered MCP capabilities.
 *
 * This class encapsulates all discovered elements (tools, resources, prompts, resource templates)
 * and provides methods to apply this state to a registry.
 *
 * @author Xentixar <xentixar@gmail.com>
 */
final class DiscoveryState
{
    /**
     * @param array<string, ToolReference>             $tools
     * @param array<string, ResourceReference>         $resources
     * @param array<string, PromptReference>           $prompts
     * @param array<string, ResourceTemplateReference> $resourceTemplates
     */
    public function __construct(
        private readonly array $tools = [],
        private readonly array $resources = [],
        private readonly array $prompts = [],
        private readonly array $resourceTemplates = [],
    ) {
    }

    /**
     * @return array<string, ToolReference>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<string, ResourceReference>
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    /**
     * @return array<string, PromptReference>
     */
    public function getPrompts(): array
    {
        return $this->prompts;
    }

    /**
     * @return array<string, ResourceTemplateReference>
     */
    public function getResourceTemplates(): array
    {
        return $this->resourceTemplates;
    }

    /**
     * Check if this state contains any discovered elements.
     */
    public function isEmpty(): bool
    {
        return empty($this->tools)
            && empty($this->resources)
            && empty($this->prompts)
            && empty($this->resourceTemplates);
    }

    /**
     * Get the total count of discovered elements.
     */
    public function getElementCount(): int
    {
        return \count($this->tools)
            + \count($this->resources)
            + \count($this->prompts)
            + \count($this->resourceTemplates);
    }

    /**
     * Get a breakdown of discovered elements by type.
     *
     * @return array{tools: int, resources: int, prompts: int, resourceTemplates: int}
     */
    public function getElementCounts(): array
    {
        return [
            'tools' => \count($this->tools),
            'resources' => \count($this->resources),
            'prompts' => \count($this->prompts),
            'resourceTemplates' => \count($this->resourceTemplates),
        ];
    }
}
