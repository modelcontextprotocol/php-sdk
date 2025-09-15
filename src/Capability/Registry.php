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
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Mcp\Server\NotificationPublisher;
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
        private readonly NotificationPublisher $notificationPublisher = new NotificationPublisher(),
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
            toolsListChanged: true,
            resources: [] !== $this->resources || [] !== $this->resourceTemplates,
            resourcesSubscribe: false,
            resourcesListChanged: true,
            prompts: [] !== $this->prompts,
            promptsListChanged: true,
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

        $this->notificationPublisher->enqueue(new ToolListChangedNotification());
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

        $this->notificationPublisher->enqueue(new ResourceListChangedNotification());
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

        // TODO: Create ResourceTemplateListChangedNotification.
        // $this->notificationPublisher->enqueue(ResourceTemplateListChangedNotification::class);
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

        $this->notificationPublisher->enqueue(new PromptListChangedNotification());
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

    public function getTools(): array
    {
        return array_map(fn (ToolReference $tool) => $tool->tool, $this->tools);
    }

    public function getResources(): array
    {
        return array_map(fn (ResourceReference $resource) => $resource->schema, $this->resources);
    }

    public function getPrompts(): array
    {
        return array_map(fn (PromptReference $prompt) => $prompt->prompt, $this->prompts);
    }

    public function getResourceTemplates(): array
    {
        return array_map(fn (ResourceTemplateReference $template) => $template->resourceTemplate,
            $this->resourceTemplates);
    }

    public function hasElements(): bool
    {
        return !empty($this->tools)
            || !empty($this->resources)
            || !empty($this->prompts)
            || !empty($this->resourceTemplates);
    }
}
