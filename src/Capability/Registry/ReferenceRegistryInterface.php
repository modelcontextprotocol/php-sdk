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

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;

/**
 * @phpstan-import-type Handler from ElementReference
 *
 * Interface for registering MCP elements.
 * Separates the concern of registering elements from accessing them.
 *
 * @author Pavel Buchnev <butschster@gmail.com>
 */
interface ReferenceRegistryInterface
{
    /**
     * Gets server capabilities based on registered elements.
     */
    public function getCapabilities(): ServerCapabilities;

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
}
