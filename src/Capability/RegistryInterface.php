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
use Mcp\Capability\Provider\DynamicPromptProviderInterface;
use Mcp\Capability\Provider\DynamicResourceProviderInterface;
use Mcp\Capability\Provider\DynamicResourceTemplateProviderInterface;
use Mcp\Capability\Provider\DynamicToolProviderInterface;
use Mcp\Capability\Registry\DynamicPromptReference;
use Mcp\Capability\Registry\DynamicResourceReference;
use Mcp\Capability\Registry\DynamicResourceTemplateReference;
use Mcp\Capability\Registry\DynamicToolReference;
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
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface RegistryInterface
{
    /**
     * Registers a tool with its handler.
     *
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     */
    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void;

    /**
     * Registers a resource with its handler.
     *
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     */
    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void;

    /**
     * Registers a resource template with its handler and completion providers.
     *
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     * @param array<string, class-string|object>                       $completionProviders
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
     * @param callable|array{0: class-string|object, 1: string}|string $handler
     * @param array<string, class-string|object>                       $completionProviders
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

    /**
     * Registers a dynamic tool provider.
     */
    public function registerDynamicToolProvider(DynamicToolProviderInterface $provider): void;

    /**
     * Registers a dynamic prompt provider.
     */
    public function registerDynamicPromptProvider(DynamicPromptProviderInterface $provider): void;

    /**
     * Registers a dynamic resource provider.
     */
    public function registerDynamicResourceProvider(DynamicResourceProviderInterface $provider): void;

    /**
     * Gets all registered dynamic tool providers.
     *
     * @return array<DynamicToolProviderInterface>
     */
    public function getDynamicToolProviders(): array;

    /**
     * Gets all registered dynamic prompt providers.
     *
     * @return array<DynamicPromptProviderInterface>
     */
    public function getDynamicPromptProviders(): array;

    /**
     * Gets all registered dynamic resource providers.
     *
     * @return array<DynamicResourceProviderInterface>
     */
    public function getDynamicResourceProviders(): array;

    /**
     * Registers a dynamic resource template provider.
     */
    public function registerDynamicResourceTemplateProvider(DynamicResourceTemplateProviderInterface $provider): void;

    /**
     * Gets all registered dynamic resource template providers.
     *
     * @return array<DynamicResourceTemplateProviderInterface>
     */
    public function getDynamicResourceTemplateProviders(): array;

    /**
     * Gets completion providers from a dynamic prompt provider.
     *
     * @return array<string, class-string|object>|null Completion providers, or null if no dynamic provider found
     */
    public function getDynamicPromptCompletionProviders(string $name): ?array;

    /**
     * Gets completion providers from a dynamic resource template provider.
     *
     * @return array<string, class-string|object>|null Completion providers, or null if no dynamic provider found
     */
    public function getDynamicResourceTemplateCompletionProviders(string $uri): ?array;

    /**
     * Gets a dynamic tool reference by name.
     *
     * Returns a reference that wraps the dynamic tool provider for uniform handling
     * with static tool references. Returns null if no dynamic provider supports the tool.
     */
    public function getDynamicTool(string $name): ?DynamicToolReference;

    /**
     * Gets a dynamic prompt reference by name.
     *
     * Returns a reference that wraps the dynamic prompt provider for uniform handling
     * with static prompt references. Returns null if no dynamic provider supports the prompt.
     */
    public function getDynamicPrompt(string $name): ?DynamicPromptReference;

    /**
     * Gets a dynamic resource reference by URI.
     *
     * Returns a reference that wraps the dynamic resource provider for uniform handling
     * with static resource references. Returns null if no dynamic provider supports the URI.
     */
    public function getDynamicResource(string $uri): ?DynamicResourceReference;

    /**
     * Gets a dynamic resource template reference by URI.
     *
     * Returns a reference that wraps the dynamic resource template provider for uniform handling
     * with static resource template references. Returns null if no dynamic provider supports the URI.
     */
    public function getDynamicResourceTemplate(string $uri): ?DynamicResourceTemplateReference;
}
