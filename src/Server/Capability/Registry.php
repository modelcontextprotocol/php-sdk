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
use Mcp\Server\Capability\Registry\Loader\LoaderInterface;
use Mcp\Server\Capability\Registry\PromptReference;
use Mcp\Server\Capability\Registry\ResourceReference;
use Mcp\Server\Capability\Registry\ResourceTemplateReference;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Server\Capability\Tool\NameValidator;
use Mcp\Server\Event\PromptListChangedEvent;
use Mcp\Server\Event\ResourceListChangedEvent;
use Mcp\Server\Event\ResourceTemplateListChangedEvent;
use Mcp\Server\Event\ToolListChangedEvent;
use Mcp\Server\Exception\InvalidCursorException;
use Mcp\Server\Exception\PartialLoadException;
use Mcp\Server\Exception\PromptNotFoundException;
use Mcp\Server\Exception\ResourceNotFoundException;
use Mcp\Server\Exception\ToolNotFoundException;
use Mcp\Server\Subscription\PublishingEventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registry implementation that manages MCP element registration and access.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Registry implements RegistryInterface, DeferredRegistryInterface
{
    /**
     * @var array<string, ToolReference>
     */
    private array $tools = [];

    /**
     * @var array<string, ResourceReference>
     */
    private array $resources = [];

    /**
     * @var array<string, PromptReference>
     */
    private array $prompts = [];

    /**
     * @var array<string, ResourceTemplateReference>
     */
    private array $resourceTemplates = [];

    private bool $loaded = false;

    /** Loads running right now, counted because loads from other fibers may interleave */
    private int $loading = 0;

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly NameValidator $nameValidator = new NameValidator(),
        private readonly ?LoaderInterface $loader = null,
    ) {
    }

    /**
     * Runs the configured loader once, on demand. Reads trigger this automatically, so element
     * loading is deferred to the first read (request time) rather than eager at build time — under a
     * persistent runtime a source not yet ready at build no longer freezes the registry empty.
     *
     * `loaded` is set only after success, so a transient failure is retried on the next read. The
     * `loading` guard lets a loader read the registry during its own run (e.g. discovery's identity
     * check) without re-entering the load.
     */
    public function load(): void
    {
        if ($this->loaded || 0 !== $this->loading || null === $this->loader) {
            return;
        }

        try {
            $this->loadFrom($this->loader);
        } catch (PartialLoadException) {
            return;
        }

        $this->loaded = true;
    }

    public function isLoaded(): bool
    {
        return $this->loaded || null === $this->loader;
    }

    /**
     * Runs $loader with the change events its registrations would dispatch suppressed, since they
     * describe the registry filling up rather than changing.
     *
     * Always runs $loader, even while another load is running, and failure propagates. Does not
     * mark the registry loaded: $loader is the caller's, and the one the constructor took is still
     * owed its run.
     */
    public function loadFrom(LoaderInterface $loader): void
    {
        ++$this->loading;
        try {
            $loader->load($this);
        } finally {
            --$this->loading;
        }
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        if (!$this->nameValidator->isValid($tool->name)) {
            $this->logger->warning(
                \sprintf('Tool name "%s" is invalid. Tool names should be 1 to 128 characters long and only contain letters (a-z, A-Z), numbers, dots, hyphens and underscores.', $tool->name),
            );
        }

        $reference = new ToolReference($tool, $handler);
        $this->tools[$tool->name] = $reference;

        $this->dispatch(new ToolListChangedEvent());

        return $reference;
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        $reference = new ResourceReference($resource, $handler);
        $this->resources[$resource->uri] = $reference;

        $this->dispatch(new ResourceListChangedEvent());

        return $reference;
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        $reference = new ResourceTemplateReference($template, $handler, $completionProviders);
        $this->resourceTemplates[$template->uriTemplate] = $reference;

        $this->dispatch(new ResourceTemplateListChangedEvent());

        return $reference;
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        $reference = new PromptReference($prompt, $handler, $completionProviders);
        $this->prompts[$prompt->name] = $reference;

        $this->dispatch(new PromptListChangedEvent());

        return $reference;
    }

    public function unregisterTool(string $name): void
    {
        if (!isset($this->tools[$name])) {
            return;
        }

        unset($this->tools[$name]);

        $this->dispatch(new ToolListChangedEvent());
    }

    public function unregisterResource(string $uri): void
    {
        if (!isset($this->resources[$uri])) {
            return;
        }

        unset($this->resources[$uri]);

        $this->dispatch(new ResourceListChangedEvent());
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        if (!isset($this->resourceTemplates[$uriTemplate])) {
            return;
        }

        unset($this->resourceTemplates[$uriTemplate]);

        $this->dispatch(new ResourceTemplateListChangedEvent());
    }

    public function unregisterPrompt(string $name): void
    {
        if (!isset($this->prompts[$name])) {
            return;
        }

        unset($this->prompts[$name]);

        $this->dispatch(new PromptListChangedEvent());
    }

    public function hasTool(string $name): bool
    {
        $this->load();

        return isset($this->tools[$name]);
    }

    public function hasResource(string $uri): bool
    {
        $this->load();

        return isset($this->resources[$uri]);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        $this->load();

        return isset($this->resourceTemplates[$uriTemplate]);
    }

    public function hasPrompt(string $name): bool
    {
        $this->load();

        return isset($this->prompts[$name]);
    }

    public function publishesListChanges(): bool
    {
        return $this->eventDispatcher instanceof PublishingEventDispatcher;
    }

    public function hasTools(): bool
    {
        $this->load();

        return [] !== $this->tools;
    }

    /**
     * @return Page<Tool>
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        if (null !== $limit) {
            $tools = [];
            foreach ($this->paginateResults($this->tools, $limit, $cursor) as $toolReference) {
                $tools[] = $toolReference->tool;
            }

            return new Page($tools, $this->calculateNextCursor(\count($this->tools), $cursor, $limit));
        }

        $tools = [];
        foreach ($this->tools as $toolReference) {
            $tools[$toolReference->tool->name] = $toolReference->tool;
        }

        return new Page($tools, null);
    }

    public function getTool(string $name): ToolReference
    {
        $this->load();

        return $this->tools[$name] ?? throw new ToolNotFoundException($name);
    }

    public function hasResources(): bool
    {
        $this->load();

        return [] !== $this->resources;
    }

    /**
     * @return Page<ResourceDefinition>
     */
    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        if (null !== $limit) {
            $resources = [];
            foreach ($this->paginateResults($this->resources, $limit, $cursor) as $resourceReference) {
                $resources[] = $resourceReference->resource;
            }

            return new Page($resources, $this->calculateNextCursor(\count($this->resources), $cursor, $limit));
        }

        $resources = [];
        foreach ($this->resources as $resourceReference) {
            $resources[$resourceReference->resource->uri] = $resourceReference->resource;
        }

        return new Page($resources, null);
    }

    public function getResource(
        string $uri,
        bool $includeTemplates = true,
    ): ResourceReference|ResourceTemplateReference {
        $this->load();

        $registration = $this->resources[$uri] ?? null;
        if ($registration) {
            return $registration;
        }

        if ($includeTemplates) {
            foreach ($this->resourceTemplates as $template) {
                if ($template->matches($uri)) {
                    return $template;
                }
            }
        }

        $this->logger->debug('No resource matched URI.', ['uri' => $uri]);

        throw new ResourceNotFoundException($uri);
    }

    public function hasResourceTemplates(): bool
    {
        $this->load();

        return [] !== $this->resourceTemplates;
    }

    /**
     * @return Page<ResourceTemplate>
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        if (null !== $limit) {
            $templates = [];
            foreach ($this->paginateResults($this->resourceTemplates, $limit, $cursor) as $templateReference) {
                $templates[] = $templateReference->resourceTemplate;
            }

            return new Page($templates, $this->calculateNextCursor(\count($this->resourceTemplates), $cursor, $limit));
        }

        $templates = [];
        foreach ($this->resourceTemplates as $templateReference) {
            $templates[$templateReference->resourceTemplate->uriTemplate] = $templateReference->resourceTemplate;
        }

        return new Page($templates, null);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        $this->load();

        return $this->resourceTemplates[$uriTemplate] ?? throw new ResourceNotFoundException($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        $this->load();

        return [] !== $this->prompts;
    }

    /**
     * @return Page<Prompt>
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        $this->load();

        if (null !== $limit) {
            $prompts = [];
            foreach ($this->paginateResults($this->prompts, $limit, $cursor) as $promptReference) {
                $prompts[] = $promptReference->prompt;
            }

            return new Page($prompts, $this->calculateNextCursor(\count($this->prompts), $cursor, $limit));
        }

        $prompts = [];
        foreach ($this->prompts as $promptReference) {
            $prompts[$promptReference->prompt->name] = $promptReference->prompt;
        }

        return new Page($prompts, null);
    }

    public function getPrompt(string $name): PromptReference
    {
        $this->load();

        return $this->prompts[$name] ?? throw new PromptNotFoundException($name);
    }

    /**
     * Suppressed while the deferred loader runs, since it only sets up initial state.
     */
    private function dispatch(object $event): void
    {
        if (0 !== $this->loading) {
            return;
        }

        $this->eventDispatcher?->dispatch($event);
    }

    /**
     * Calculate next cursor for pagination.
     *
     * @param int         $totalItems    Count of all items
     * @param string|null $currentCursor Current cursor position
     * @param int         $limit         Number requested/returned per page
     */
    private function calculateNextCursor(int $totalItems, ?string $currentCursor, int $limit): ?string
    {
        $currentOffset = 0;

        if (null !== $currentCursor) {
            $decodedCursor = base64_decode($currentCursor, true);
            if (false !== $decodedCursor && is_numeric($decodedCursor)) {
                $currentOffset = (int) $decodedCursor;
            }
        }

        $nextOffset = $currentOffset + $limit;

        if ($nextOffset < $totalItems) {
            return base64_encode((string) $nextOffset);
        }

        return null;
    }

    /**
     * Helper method to paginate results using cursor-based pagination.
     *
     * @template T
     *
     * @param array<int|string, T> $items  The full array of items to paginate
     * @param int                  $limit  Maximum number of items to return
     * @param string|null          $cursor Base64 encoded offset position
     *
     * @return list<T> Paginated results
     *
     * @throws InvalidCursorException When cursor is invalid (MCP error code -32602)
     */
    private function paginateResults(array $items, int $limit, ?string $cursor = null): array
    {
        $offset = 0;
        if (null !== $cursor) {
            $decodedCursor = base64_decode($cursor, true);

            if (false === $decodedCursor || !is_numeric($decodedCursor)) {
                throw new InvalidCursorException($cursor);
            }

            $offset = (int) $decodedCursor;

            // Validate offset is within reasonable bounds
            if ($offset < 0 || $offset > \count($items)) {
                throw new InvalidCursorException($cursor);
            }
        }

        return array_values(\array_slice($items, $offset, $limit));
    }
}
