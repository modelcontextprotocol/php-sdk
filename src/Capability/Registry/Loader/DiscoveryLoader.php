<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry\Loader;

use Mcp\Capability\Discovery\DiscovererInterface;
use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\RegistryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @internal
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class DiscoveryLoader implements LoaderInterface
{
    private DiscoveryState $owned;

    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     * @param string[] $namePatterns
     */
    public function __construct(
        private string $basePath,
        private array $scanDirs,
        private array $excludeDirs,
        private DiscovererInterface $discoverer,
        private array $namePatterns = DiscovererInterface::DEFAULT_NAME_PATERNS,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->owned = new DiscoveryState();
    }

    public function load(RegistryInterface $registry): void
    {
        $discovered = $this->discoverer->discover($this->basePath, $this->scanDirs, $this->excludeDirs, $this->namePatterns);

        $this->unregisterOwned($registry, $this->owned->obsoletedBy($discovered));
        $this->owned = $this->writeDiscovered($registry, $discovered);
    }

    /**
     * Unregisters entries we previously wrote that the registry still attributes to us.
     * Entries overwritten by someone else are left untouched (identity check fails).
     */
    private function unregisterOwned(RegistryInterface $registry, DiscoveryState $obsolete): void
    {
        foreach ($obsolete->getTools() as $name => $owned) {
            if ($registry->hasTool($name) && $registry->getTool($name) === $owned) {
                $registry->unregisterTool($name);
            }
        }
        foreach ($obsolete->getResources() as $uri => $owned) {
            if ($registry->hasResource($uri) && $registry->getResource($uri, false) === $owned) {
                $registry->unregisterResource($uri);
            }
        }
        foreach ($obsolete->getResourceTemplates() as $uriTemplate => $owned) {
            if ($registry->hasResourceTemplate($uriTemplate) && $registry->getResourceTemplate($uriTemplate) === $owned) {
                $registry->unregisterResourceTemplate($uriTemplate);
            }
        }
        foreach ($obsolete->getPrompts() as $name => $owned) {
            if ($registry->hasPrompt($name) && $registry->getPrompt($name) === $owned) {
                $registry->unregisterPrompt($name);
            }
        }
    }

    /**
     * Writes the discovered state into the registry, skipping entries that a conflicting
     * registration already holds. Returns the new owned state (only the writes we actually performed).
     */
    private function writeDiscovered(RegistryInterface $registry, DiscoveryState $discovered): DiscoveryState
    {
        $tools = [];
        foreach ($discovered->getTools() as $name => $reference) {
            if (!$this->mayWriteTool($registry, $name)) {
                continue;
            }
            $tools[$name] = $registry->registerTool($reference->tool, $reference->handler);
        }

        $resources = [];
        foreach ($discovered->getResources() as $uri => $reference) {
            if (!$this->mayWriteResource($registry, $uri)) {
                continue;
            }
            $resources[$uri] = $registry->registerResource($reference->resource, $reference->handler);
        }

        $resourceTemplates = [];
        foreach ($discovered->getResourceTemplates() as $uriTemplate => $reference) {
            if (!$this->mayWriteResourceTemplate($registry, $uriTemplate)) {
                continue;
            }
            $resourceTemplates[$uriTemplate] = $registry->registerResourceTemplate(
                $reference->resourceTemplate,
                $reference->handler,
                $reference->completionProviders,
            );
        }

        $prompts = [];
        foreach ($discovered->getPrompts() as $name => $reference) {
            if (!$this->mayWritePrompt($registry, $name)) {
                continue;
            }
            $prompts[$name] = $registry->registerPrompt(
                $reference->prompt,
                $reference->handler,
                $reference->completionProviders,
            );
        }

        return new DiscoveryState($tools, $resources, $prompts, $resourceTemplates);
    }

    private function mayWriteTool(RegistryInterface $registry, string $name): bool
    {
        if (!$registry->hasTool($name) || $registry->getTool($name) === ($this->owned->getTools()[$name] ?? null)) {
            return true;
        }

        $this->logger->debug(\sprintf(
            'Ignoring discovered tool "%s": a conflicting manual or runtime registration already exists.',
            $name,
        ));

        return false;
    }

    private function mayWriteResource(RegistryInterface $registry, string $uri): bool
    {
        if (!$registry->hasResource($uri) || $registry->getResource($uri, false) === ($this->owned->getResources()[$uri] ?? null)) {
            return true;
        }

        $this->logger->debug(\sprintf(
            'Ignoring discovered resource "%s": a conflicting manual or runtime registration already exists.',
            $uri,
        ));

        return false;
    }

    private function mayWriteResourceTemplate(RegistryInterface $registry, string $uriTemplate): bool
    {
        if (!$registry->hasResourceTemplate($uriTemplate) || $registry->getResourceTemplate($uriTemplate) === ($this->owned->getResourceTemplates()[$uriTemplate] ?? null)) {
            return true;
        }

        $this->logger->debug(\sprintf(
            'Ignoring discovered resource template "%s": a conflicting manual or runtime registration already exists.',
            $uriTemplate,
        ));

        return false;
    }

    private function mayWritePrompt(RegistryInterface $registry, string $name): bool
    {
        if (!$registry->hasPrompt($name) || $registry->getPrompt($name) === ($this->owned->getPrompts()[$name] ?? null)) {
            return true;
        }

        $this->logger->debug(\sprintf(
            'Ignoring discovered prompt "%s": a conflicting manual or runtime registration already exists.',
            $name,
        ));

        return false;
    }
}
