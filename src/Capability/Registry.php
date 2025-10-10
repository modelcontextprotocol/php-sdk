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
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Page;
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

    private ServerCapabilities $serverCapabilities;

    private bool $loggingMessageNotificationEnabled = false;

    private ?LoggingLevel $currentLoggingMessageNotificationLevel = null;

    public function __construct(
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Enables logging message notifications for this registry.
     */
    public function enableLoggingMessageNotification(): void
    {
        $this->loggingMessageNotificationEnabled = true;
    }

    /**
     * Checks if logging message notification capability is enabled.
     *
     * @return bool True if logging message notification capability is enabled, false otherwise
     */
    public function isLoggingMessageNotificationEnabled(): bool
    {
        return $this->loggingMessageNotificationEnabled;
    }

    /**
     * Sets the current logging message notification level for the client.
     *
     * This determines which log messages should be sent to the client.
     * Only messages at this level and higher (more severe) will be sent.
     */
    public function setLoggingMessageNotificationLevel(LoggingLevel $level): void
    {
        $this->currentLoggingMessageNotificationLevel = $level;
    }

    /**
     * Gets the current logging message notification level set by the client.
     *
     * @return LoggingLevel|null The current log level, or null if not set
     */
    public function getLoggingMessageNotificationLevel(): ?LoggingLevel
    {
        return $this->currentLoggingMessageNotificationLevel;
    }

    public function getCapabilities(): ServerCapabilities
    {
        if (!$this->hasElements()) {
            $this->logger->info('No capabilities registered on server.');
        }

        return $this->serverCapabilities ?? new ServerCapabilities(
            tools: [] !== $this->tools,
            toolsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            resources: [] !== $this->resources || [] !== $this->resourceTemplates,
            resourcesSubscribe: false,
            resourcesListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            prompts: [] !== $this->prompts,
            promptsListChanged: $this->eventDispatcher instanceof EventDispatcherInterface,
            logging: $this->loggingMessageNotificationEnabled,
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

    public function getPrompt(string $name): ?PromptReference
    {
        return $this->prompts[$name] ?? null;
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

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        $resources = [];
        foreach ($this->resources as $resourceReference) {
            $resources[$resourceReference->schema->uri] = $resourceReference->schema;
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

    public function hasElements(): bool
    {
        return !empty($this->tools)
            || !empty($this->resources)
            || !empty($this->prompts)
            || !empty($this->resourceTemplates);
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

    public function setServerCapabilities(ServerCapabilities $serverCapabilities): void
    {
        $this->serverCapabilities = $serverCapabilities;
    }
}
