<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry;

use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\Capability\DeferredRegistryInterface;
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\PartialLoadException;

/**
 * Defers a loader over any registry to the first read, leaving the registry itself unaware of it.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class LazyRegistry implements RegistryInterface, DeferredRegistryInterface
{
    private bool $loaded = false;
    private bool $loading = false;

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly LoaderInterface $loader,
    ) {
    }

    public function load(): void
    {
        if ($this->loading) {
            return;
        }

        if (!$this->loaded) {
            $this->loading = true;
            try {
                $this->loadFrom($this->loader);
                $this->loaded = true;
            } catch (PartialLoadException) {
                // what loaded is served, and the load stays pending
            } finally {
                $this->loading = false;
            }
        }

        if ($this->registry instanceof DeferredRegistryInterface) {
            $this->registry->load();
        }
    }

    public function isLoaded(): bool
    {
        return $this->loaded && (!$this->registry instanceof DeferredRegistryInterface || $this->registry->isLoaded());
    }

    public function loadFrom(LoaderInterface $loader): void
    {
        if ($this->registry instanceof DeferredRegistryInterface) {
            $this->registry->loadFrom($loader);

            return;
        }

        $loader->load($this->registry);
    }

    public function publishesListChanges(): bool
    {
        return $this->registry->publishesListChanges();
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

    public function hasResources(): bool
    {
        $this->load();

        return $this->registry->hasResources();
    }

    public function hasResourceTemplates(): bool
    {
        $this->load();

        return $this->registry->hasResourceTemplates();
    }

    public function hasPrompts(): bool
    {
        $this->load();

        return $this->registry->hasPrompts();
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getTools($limit, $cursor);
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getResources($limit, $cursor);
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getResourceTemplates($limit, $cursor);
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        return $this->registry->getPrompts($limit, $cursor);
    }

    public function getTool(string $name): ToolReference
    {
        $this->load();

        return $this->registry->getTool($name);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        $this->load();

        return $this->registry->getResource($uri, $includeTemplates);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        $this->load();

        return $this->registry->getResourceTemplate($uriTemplate);
    }

    public function getPrompt(string $name): PromptReference
    {
        $this->load();

        return $this->registry->getPrompt($name);
    }
}
