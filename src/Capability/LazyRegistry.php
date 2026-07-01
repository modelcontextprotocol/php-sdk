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

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * Decorates a registry so its loader runs on first read instead of eagerly at build time.
 *
 * Under a persistent runtime (e.g. FrankenPHP worker mode) the server is built once, so eager
 * loading would freeze the registry to a data source not yet ready at build (cold cache) for the
 * whole process. Deferring to the first read runs the load once, at request time. Writes are
 * delegated without loading, so registrations made before the first read survive it.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class LazyRegistry implements RegistryInterface
{
    private bool $loaded = false;

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly LoaderInterface $loader,
    ) {
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->registry->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->registry->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = []): ResourceTemplateReference
    {
        return $this->registry->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = []): PromptReference
    {
        return $this->registry->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->registry->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->registry->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->registry->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->registry->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        $this->load();

        return $this->registry->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        $this->load();

        return $this->registry->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        $this->load();

        return $this->registry->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        $this->load();

        return $this->registry->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        $this->load();

        return $this->registry->hasTools();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getTools($limit, $cursor);
    }

    public function getTool(string $name): ToolReference
    {
        $this->load();

        return $this->registry->getTool($name);
    }

    public function hasResources(): bool
    {
        $this->load();

        return $this->registry->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        $this->load();

        return $this->registry->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        $this->load();

        return $this->registry->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        $this->load();

        return $this->registry->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        $this->load();

        return $this->registry->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        $this->load();

        return $this->registry->getPrompt($name);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loader->load($this->registry);
        $this->loaded = true;
    }
}
