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

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-import-type CallableArray from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Registry
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
        private readonly ReferenceHandler $referenceHandler = new ReferenceHandler(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getCapabilities(): ServerCapabilities
    {
        if (!$this->hasElements()) {
            $this->logger->info('No capabilities registered on server.');
        }

        return new ServerCapabilities(
            tools: true, // [] !== $this->tools,
            toolsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            resources: [] !== $this->resources || [] !== $this->resourceTemplates,
            resourcesSubscribe: false,
            resourcesListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            prompts: [] !== $this->prompts,
            promptsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            logging: false, // true,
            completions: true,
        );
    }

    /**
     * @param callable|CallableArray|string $handler
     */
    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $toolName = $tool->name;
        $existing = $this->tools[$toolName] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered tool '{$toolName}' as it conflicts with a manually registered one.");

            return;
        }

        $this->tools[$toolName] = new ToolReference($tool, $handler, $isManual);

        $this->eventDispatcher?->dispatch(new ToolListChangedEvent());
    }

    /**
     * @param callable|CallableArray|string $handler
     */
    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $uri = $resource->uri;
        $existing = $this->resources[$uri] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered resource '{$uri}' as it conflicts with a manually registered one.");

            return;
        }

        $this->resources[$uri] = new ResourceReference($resource, $handler, $isManual);

        $this->eventDispatcher?->dispatch(new ResourceListChangedEvent());
    }

    /**
     * @param callable|CallableArray|string      $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $uriTemplate = $template->uriTemplate;
        $existing = $this->resourceTemplates[$uriTemplate] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered template '{$uriTemplate}' as it conflicts with a manually registered one.");

            return;
        }

        $this->resourceTemplates[$uriTemplate] = new ResourceTemplateReference($template, $handler, $isManual, $completionProviders);

        $this->eventDispatcher?->dispatch(new ResourceTemplateListChangedEvent());
    }

    /**
     * @param callable|CallableArray|string      $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $promptName = $prompt->name;
        $existing = $this->prompts[$promptName] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug("Ignoring discovered prompt '{$promptName}' as it conflicts with a manually registered one.");

            return;
        }

        $this->prompts[$promptName] = new PromptReference($prompt, $handler, $isManual, $completionProviders);

        $this->eventDispatcher?->dispatch(new PromptListChangedEvent());
    }

    /**
     * Checks if any elements (manual or discovered) are currently registered.
     */
    public function hasElements(): bool
    {
        return !empty($this->tools)
            || !empty($this->resources)
            || !empty($this->prompts)
            || !empty($this->resourceTemplates);
    }

    /**
     * Clear discovered elements from registry.
     */
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

    public function handleCallTool(string $name, array $arguments): array
    {
        $reference = $this->getTool($name);

        if (null === $reference) {
            throw new InvalidArgumentException(\sprintf('Tool "%s" is not registered.', $name));
        }

        return $reference->formatResult(
            $this->referenceHandler->handle($reference, $arguments)
        );
    }

    public function getTool(string $name): ?ToolReference
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return ResourceContents[]
     */
    public function handleReadResource(string $uri): array
    {
        $reference = $this->getResource($uri);

        if (null === $reference) {
            throw new InvalidArgumentException(\sprintf('Resource "%s" is not registered.', $uri));
        }

        return $reference->formatResult(
            $this->referenceHandler->handle($reference, ['uri' => $uri]),
            $uri,
        );
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference|null
    {
        $registration = $this->resources[$uri] ?? null;
        if ($registration) {
            return $registration;
        }

        if (!$includeTemplates) {
            return null;
        }

        foreach ($this->resourceTemplates as $template) {
            if ($template->matches($uri)) {
                return $template;
            }
        }

        $this->logger->debug('No resource matched URI.', ['uri' => $uri]);

        return null;
    }

    public function getResourceTemplate(string $uriTemplate): ?ResourceTemplateReference
    {
        return $this->resourceTemplates[$uriTemplate] ?? null;
    }

    /**
     * @return PromptMessage[]
     */
    public function handleGetPrompt(string $name, ?array $arguments): array
    {
        $reference = $this->getPrompt($name);

        if (null === $reference) {
            throw new InvalidArgumentException(\sprintf('Prompt "%s" is not registered.', $name));
        }

        return $reference->formatResult(
            $this->referenceHandler->handle($reference, $arguments)
        );
    }

    public function getPrompt(string $name): ?PromptReference
    {
        return $this->prompts[$name] ?? null;
    }

    /**
     * @return array<string, Tool>
     */
    public function getTools(?int $limit = null, ?string $cursor = null): array
    {
        $tools = [];
        foreach ($this->tools as $toolReference) {
            $tools[] = $toolReference->tool;
        }

        if (null === $limit) {
            return $tools;
        }

        return $this->paginateResults($tools, $limit, $cursor);
    }

    /**
     * @return array<string, resource>
     */
    public function getResources(?int $limit = null, ?string $cursor = null): array
    {
        $resources = [];
        foreach ($this->resources as $resource) {
            $resources[] = $resource->schema;
        }

        if (null === $limit) {
            return $resources;
        }

        return $this->paginateResults($resources, $limit, $cursor);
    }

    /**
     * @return array<string, Prompt>
     */
    public function getPrompts(?int $limit = null, ?string $cursor = null): array
    {
        $prompts = [];
        foreach ($this->prompts as $promptReference) {
            $prompts[] = $promptReference->prompt;
        }

        if (null === $limit) {
            return $prompts;
        }

        return $this->paginateResults($prompts, $limit, $cursor);
    }

    /**
     * Get total count of tools.
     */
    public function getToolsCount(): int
    {
        return \count($this->tools);
    }

    /**
     * Get total count of prompts.
     */
    public function getPromptsCount(): int
    {
        return \count($this->prompts);
    }

    /**
     * Get total count of resources.
     */
    public function getResourcesCount(): int
    {
        return \count($this->resources);
    }

    /**
     * @return array<string, ResourceTemplate>
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): array
    {
        $templates = array_map(fn ($template) => $template->resourceTemplate, $this->resourceTemplates);

        if (null === $limit) {
            return $templates;
        }

        return $this->paginateResults($templates, $limit, $cursor);
    }

    /**
     * @throws InvalidCursorException
     */
    private function paginateResults(array $items, int $limit, ?string $cursor = null): array
    {
        $offset = 0;
        if (null !== $cursor) {
            $decodedCursor = base64_decode($cursor, true);

            if (false === $decodedCursor || !is_numeric($decodedCursor)) {
                throw new InvalidCursorException($cursor);
            }

            $offset = $decodedCursor;

            // Validate offset is within reasonable bounds
            if ($offset < 0 || $offset > \count($items)) {
                throw new InvalidCursorException($cursor);
            }
        }

        return \array_slice($items, $offset, $limit);
    }

    /**
     * Calculate next cursor for pagination.
     */
    public function calculateNextCursor(int $totalCount, ?string $currentCursor, int $returnedCount): ?string
    {
        $currentOffset = 0;

        if (null !== $currentCursor) {
            $decodedCursor = base64_decode($currentCursor, true);
            if (false !== $decodedCursor && is_numeric($decodedCursor)) {
                $currentOffset = $decodedCursor;
            }
        }

        $nextOffset = $currentOffset + $returnedCount;

        // If we have more items available, return next cursor
        if ($nextOffset < $totalCount) {
            return base64_encode((string) $nextOffset);
        }

        return null;
    }
}
