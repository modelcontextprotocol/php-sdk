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
use Mcp\Schema\ResourceDefinition;
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

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        if (!$this->nameValidator->isValid($tool->name)) {
            $this->logger->warning(
                \sprintf('Tool name "%s" is invalid. Tool names should only contain letters (a-z, A-Z), numbers, dots, hyphens, underscores, and forward slashes.', $tool->name),
            );
        }

        $reference = new ToolReference($tool, $handler);
        $this->tools[$tool->name] = $reference;

        $this->eventDispatcher?->dispatch(new ToolListChangedEvent());

        return $reference;
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        $reference = new ResourceReference($resource, $handler);
        $this->resources[$resource->uri] = $reference;

        $this->eventDispatcher?->dispatch(new ResourceListChangedEvent());

        return $reference;
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        $reference = new ResourceTemplateReference($template, $handler, $completionProviders);
        $this->resourceTemplates[$template->uriTemplate] = $reference;

        $this->eventDispatcher?->dispatch(new ResourceTemplateListChangedEvent());

        return $reference;
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        $reference = new PromptReference($prompt, $handler, $completionProviders);
        $this->prompts[$prompt->name] = $reference;

        $this->eventDispatcher?->dispatch(new PromptListChangedEvent());

        return $reference;
    }

    public function unregisterTool(string $name): void
    {
        if (!isset($this->tools[$name])) {
            return;
        }

        unset($this->tools[$name]);

        $this->eventDispatcher?->dispatch(new ToolListChangedEvent());
    }

    public function unregisterResource(string $uri): void
    {
        if (!isset($this->resources[$uri])) {
            return;
        }

        unset($this->resources[$uri]);

        $this->eventDispatcher?->dispatch(new ResourceListChangedEvent());
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        if (!isset($this->resourceTemplates[$uriTemplate])) {
            return;
        }

        unset($this->resourceTemplates[$uriTemplate]);

        $this->eventDispatcher?->dispatch(new ResourceTemplateListChangedEvent());
    }

    public function unregisterPrompt(string $name): void
    {
        if (!isset($this->prompts[$name])) {
            return;
        }

        unset($this->prompts[$name]);

        $this->eventDispatcher?->dispatch(new PromptListChangedEvent());
    }

    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function hasResource(string $uri): bool
    {
        return isset($this->resources[$uri]);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return isset($this->resourceTemplates[$uriTemplate]);
    }

    public function hasPrompt(string $name): bool
    {
        return isset($this->prompts[$name]);
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
