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

use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
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
 * Registry implementation that manages MCP element registration and access.
 * Implements both ReferenceProvider (for access) and ReferenceRegistry (for registration)
 * following the Interface Segregation Principle.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * @author Pavel Buchnev   <butschster@gmail.com>
 */
final class Registry implements ReferenceProviderInterface, ReferenceRegistryInterface
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
    ) {
    }

    public function getCapabilities(): ServerCapabilities
    {
        if (!$this->hasElements()) {
            $this->logger->info('No capabilities registered on server.');
        }

        return new ServerCapabilities(
            tools: [] !== $this->tools,
            toolsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            resources: [] !== $this->resources || [] !== $this->resourceTemplates,
            resourcesSubscribe: false,
            resourcesListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            prompts: [] !== $this->prompts,
            promptsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            logging: false,
            completions: true,
        );
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $toolName = $tool->name;
        $existing = $this->tools[$toolName] ?? null;

        if ($existing && !$isManual && $existing->isManual) {
            $this->logger->debug(
                "Ignoring discovered tool '{$toolName}' as it conflicts with a manually registered one.",
            );

            return;
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
                "Ignoring discovered resource '{$uri}' as it conflicts with a manually registered one.",
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
                "Ignoring discovered template '{$uriTemplate}' as it conflicts with a manually registered one.",
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
                "Ignoring discovered prompt '{$promptName}' as it conflicts with a manually registered one.",
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

    public function getResource(
        string $uri,
        bool $includeTemplates = true,
    ): ResourceReference|ResourceTemplateReference|null {
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
     * @return list<Tool>
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
     * @return list<resource>
     */
    public function getResources(?int $limit = null, ?string $cursor = null): array
    {
        $resources = [];
        foreach ($this->resources as $resourceReference) {
            $resources[] = $resourceReference->schema;
        }

        if (null === $limit) {
            return $resources;
        }

        return $this->paginateResults($resources, $limit, $cursor);
    }

    /**
     * @return list<Prompt>
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
     * @return list<ResourceTemplate>
     */
    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): array
    {
        $templates = [];
        foreach ($this->resourceTemplates as $templateReference) {
            $templates[] = $templateReference->resourceTemplate;
        }

        if (null === $limit) {
            return $templates;
        }

        return $this->paginateResults($templates, $limit, $cursor);
    }

    /**
     * Helper method to paginate results using cursor-based pagination.
     *
     * @param list<mixed> $items  The full array of items to paginate
     * @param int         $limit  Maximum number of items to return
     * @param string|null $cursor Base64 encoded offset position
     *
     * @return list<mixed> Paginated results
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

        $result = [];
        $count = 0;
        $currentIndex = 0;

        foreach ($items as $item) {
            if ($currentIndex >= $offset && $count < $limit) {
                $result[] = $item;
                ++$count;
            }
            ++$currentIndex;
        }

        return $result;
    }

    /**
     * Calculate next cursor for pagination.
     *
     * @param list<mixed> $allItems      The complete array of items
     * @param string|null $currentCursor Current cursor position
     * @param int         $returnedCount Number of items actually returned
     */
    public function calculateNextCursor(array $allItems, ?string $currentCursor, int $returnedCount): ?string
    {
        $currentOffset = 0;

        if (null !== $currentCursor) {
            $decodedCursor = base64_decode($currentCursor, true);
            if (false !== $decodedCursor && is_numeric($decodedCursor)) {
                $currentOffset = (int) $decodedCursor;
            }
        }

        $nextOffset = $currentOffset + $returnedCount;

        // If we have more items available, return next cursor
        if ($nextOffset < \count($allItems)) {
            return base64_encode((string) $nextOffset);
        }

        return null;
    }
}
