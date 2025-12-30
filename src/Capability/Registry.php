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
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\Tool\NameValidator;
use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Exception\InvalidCursorException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Registry implementation that manages MCP element registration and access.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Registry implements RegistryInterface
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

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly NameValidator $nameValidator = new NameValidator(),
    ) {
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $toolName = $tool->name;
        $existing = $this->tools[$toolName] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug(
                \sprintf('Ignoring discovered tool "%s" as it conflicts with a manually registered one.', $toolName),
            );

            return;
        }

        if (!$this->nameValidator->isValid($toolName)) {
            $this->logger->warning(
                \sprintf('Tool name "%s" is invalid. Tool names should only contain letters (a-z, A-Z), numbers, dots, hyphens, underscores, and forward slashes.', $toolName),
            );
        }

        $this->tools[$toolName] = new ToolReference($tool, $handler, $isManual);

        $this->eventDispatcher?->dispatch(new ToolListChangedEvent());
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $uri = $resource->uri;
        $existing = $this->resources[$uri] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug(
                \sprintf('Ignoring discovered resource "%s" as it conflicts with a manually registered one.', $uri),
            );

            return;
        }

        $this->resources[$uri] = new ResourceReference($resource, $handler, $isManual);

        $this->eventDispatcher?->dispatch(new ResourceListChangedEvent());
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $uriTemplate = $template->uriTemplate;
        $existing = $this->resourceTemplates[$uriTemplate] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug(
                \sprintf('Ignoring discovered template "%s" as it conflicts with a manually registered one.', $uriTemplate),
            );

            return;
        }

        $this->resourceTemplates[$uriTemplate] = new ResourceTemplateReference(
            $template,
            $handler,
            $isManual,
            $completionProviders,
        );

        $this->eventDispatcher?->dispatch(new ResourceTemplateListChangedEvent());
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $promptName = $prompt->name;
        $existing = $this->prompts[$promptName] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug(
                \sprintf('Ignoring discovered prompt "%s" as it conflicts with a manually registered one.', $promptName),
            );

            return;
        }

        $this->prompts[$promptName] = new PromptReference($prompt, $handler, $isManual, $completionProviders);

        $this->eventDispatcher?->dispatch(new PromptListChangedEvent());
    }

    public function clear(): void
    {
        $clearCount = 0;

        foreach ($this->tools as $name => $tool) {
            if (!$tool->isManual) {
                unset($this->tools[$name]);
                ++$clearCount;
            }
        }
        foreach ($this->resources as $uri => $resource) {
            if (!$resource->isManual) {
                unset($this->resources[$uri]);
                ++$clearCount;
            }
        }
        foreach ($this->prompts as $name => $prompt) {
            if (!$prompt->isManual) {
                unset($this->prompts[$name]);
                ++$clearCount;
            }
        }
        foreach ($this->resourceTemplates as $uriTemplate => $template) {
            if (!$template->isManual) {
                unset($this->resourceTemplates[$uriTemplate]);
                ++$clearCount;
            }
        }

        if ($clearCount > 0) {
            $this->logger->debug(\sprintf('Removed %d discovered elements from internal registry.', $clearCount));
        }
    }

    public function hasTools(): bool
    {
        return [] !== $this->tools;
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $tools = [];
        foreach ($this->tools as $toolReference) {
            $tools[$toolReference->tool->name] = $toolReference->tool;
        }

        if (null === $limit) {
            return new Page($tools, null);
        }

        $paginatedTools = $this->paginateResults($tools, $limit, $cursor);

        $nextCursor = $this->calculateNextCursor(
            \count($tools),
            $cursor,
            $limit
        );

        return new Page($paginatedTools, $nextCursor);
    }

    public function getTool(string $name): ToolReference
    {
        return $this->tools[$name] ?? throw new ToolNotFoundException($name);
    }

    public function hasResources(): bool
    {
        return [] !== $this->resources;
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $resources = [];
        foreach ($this->resources as $resourceReference) {
            $resources[$resourceReference->resource->uri] = $resourceReference->resource;
        }

        if (null === $limit) {
            return new Page($resources, null);
        }

        $paginatedResources = $this->paginateResults($resources, $limit, $cursor);

        $nextCursor = $this->calculateNextCursor(
            \count($resources),
            $cursor,
            $limit
        );

        return new Page($paginatedResources, $nextCursor);
    }

    public function getResource(
        string $uri,
        bool $includeTemplates = true,
    ): ResourceReference|ResourceTemplateReference {
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
        return [] !== $this->resourceTemplates;
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        $templates = [];
        foreach ($this->resourceTemplates as $templateReference) {
            $templates[$templateReference->resourceTemplate->uriTemplate] = $templateReference->resourceTemplate;
        }

        if (null === $limit) {
            return new Page($templates, null);
        }

        $paginatedTemplates = $this->paginateResults($templates, $limit, $cursor);

        $nextCursor = $this->calculateNextCursor(
            \count($templates),
            $cursor,
            $limit
        );

        return new Page($paginatedTemplates, $nextCursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->resourceTemplates[$uriTemplate] ?? throw new ResourceNotFoundException($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return [] !== $this->prompts;
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        $prompts = [];
        foreach ($this->prompts as $promptReference) {
            $prompts[$promptReference->prompt->name] = $promptReference->prompt;
        }

        if (null === $limit) {
            return new Page($prompts, null);
        }

        $paginatedPrompts = $this->paginateResults($prompts, $limit, $cursor);

        $nextCursor = $this->calculateNextCursor(
            \count($prompts),
            $cursor,
            $limit
        );

        return new Page($paginatedPrompts, $nextCursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->prompts[$name] ?? throw new PromptNotFoundException($name);
    }

    /**
     * Get the current discovery state (only discovered elements, not manual ones).
     */
    public function getDiscoveryState(): DiscoveryState
    {
        return new DiscoveryState(
            tools: array_filter($this->tools, fn ($tool) => !$tool->isManual),
            resources: array_filter($this->resources, fn ($resource) => !$resource->isManual),
            prompts: array_filter($this->prompts, fn ($prompt) => !$prompt->isManual),
            resourceTemplates: array_filter($this->resourceTemplates, fn ($template) => !$template->isManual),
        );
    }

    /**
     * Set discovery state, replacing all discovered elements.
     * Manual elements are preserved.
     */
    public function setDiscoveryState(DiscoveryState $state): void
    {
        // Clear existing discovered elements
        $this->clear();

        // Import new discovered elements
        foreach ($state->getTools() as $name => $tool) {
            $this->tools[$name] = $tool;
        }

        foreach ($state->getResources() as $uri => $resource) {
            $this->resources[$uri] = $resource;
        }

        foreach ($state->getPrompts() as $name => $prompt) {
            $this->prompts[$name] = $prompt;
        }

        foreach ($state->getResourceTemplates() as $uriTemplate => $template) {
            $this->resourceTemplates[$uriTemplate] = $template;
        }

        // Dispatch events for the imported elements
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            if (!empty($state->getTools())) {
                $this->eventDispatcher->dispatch(new ToolListChangedEvent());
            }
            if (!empty($state->getResources()) || !empty($state->getResourceTemplates())) {
                $this->eventDispatcher->dispatch(new ResourceListChangedEvent());
            }
            if (!empty($state->getPrompts())) {
                $this->eventDispatcher->dispatch(new PromptListChangedEvent());
            }
        }
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
     * @param array<int|string, mixed> $items  The full array of items to paginate The full array of items to paginate
     * @param int                      $limit  Maximum number of items to return
     * @param string|null              $cursor Base64 encoded offset position
     *
     * @return array<int|string, mixed> Paginated results
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
