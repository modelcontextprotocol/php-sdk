<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Capability\DeferredRegistryInterface;
use Mcp\Server\Capability\RegistryInterface;

/**
 * Answers which capabilities a registry supports, at the time a request asks for them.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RegistryCapabilities
{
    /**
     * @param array<string, array<string, mixed>> $extensions
     */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly bool $hasNotificationBus,
        private readonly array $extensions = [],
    ) {
    }

    public function detect(): ServerCapabilities
    {
        $canGrow = false;
        if ($this->registry instanceof DeferredRegistryInterface) {
            $this->registry->load();
            // Hiding the kinds of elements that still arrive would hide them from the client for the whole session.
            $canGrow = !$this->registry->isLoaded();
        }

        $hasTools = $canGrow || $this->registry->hasTools();
        $hasResources = $canGrow || $this->registry->hasResources() || $this->registry->hasResourceTemplates();
        $hasPrompts = $canGrow || $this->registry->hasPrompts();

        // Only the bus delivers list changes, and only if the registry publishes to it.
        $listChanged = $this->hasNotificationBus && $this->registry->publishesListChanges();

        return new ServerCapabilities(
            tools: $hasTools,
            toolsListChanged: $listChanged,
            resources: $hasResources,
            resourcesSubscribe: $hasResources,
            resourcesListChanged: $listChanged,
            prompts: $hasPrompts,
            promptsListChanged: $listChanged,
            logging: true,
            completions: true,
            extensions: $this->extensions ?: null,
        );
    }
}
