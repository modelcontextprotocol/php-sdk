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

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Event\PromptListChangedEvent;
use Mcp\Event\ResourceListChangedEvent;
use Mcp\Event\ResourceTemplateListChangedEvent;
use Mcp\Event\ToolListChangedEvent;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\ServerCapabilities;
use Mcp\Schema\Tool;
use Psr\EventDispatcher\EventDispatcherInterface;

final class DispatchableRegistry implements ReferenceRegistryInterface
{
    public function __construct(
        private readonly ReferenceRegistryInterface $referenceProvider,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function getCapabilities(): ServerCapabilities
    {
        $capabilities = $this->referenceProvider->getCapabilities();

        if (null !== $this->eventDispatcher) {
            return $capabilities->withEvents();
        }

        return $capabilities;
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $this->referenceProvider->registerTool($tool, $handler, $isManual);
        $this->eventDispatcher?->dispatch(new ToolListChangedEvent());
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $this->referenceProvider->registerResource($resource, $handler, $isManual);
        $this->eventDispatcher?->dispatch(new ResourceListChangedEvent());
    }

    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $this->referenceProvider->registerResourceTemplate($template, $handler, $completionProviders, $isManual);
        $this->eventDispatcher?->dispatch(new ResourceTemplateListChangedEvent());
    }

    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
        bool $isManual = false,
    ): void {
        $this->referenceProvider->registerPrompt($prompt, $handler, $completionProviders, $isManual);

        $this->eventDispatcher?->dispatch(new PromptListChangedEvent());
    }

    public function clear(): void
    {
        $this->referenceProvider->clear();
        // TODO: are there any events to dispatch here?
    }
}
