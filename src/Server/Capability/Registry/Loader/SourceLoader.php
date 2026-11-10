<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry\Loader;

use Mcp\Server\Capability\Registry\Source\ElementSourceInterface;
use Mcp\Server\Capability\RegistryInterface;
use Mcp\Server\Exception\ElementSourceException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes the elements of a source into a registry.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SourceLoader implements LoaderInterface
{
    /**
     * @param bool $onlyMissing keep what the registry already holds under the same name or URI
     */
    public function __construct(
        private readonly ElementSourceInterface $source,
        private readonly bool $onlyMissing = false,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        try {
            $collection = $this->source->provide();
        } catch (\Throwable $e) {
            throw new ElementSourceException(\sprintf('Element source "%s" failed: %s', $this->source::class, $e->getMessage()), previous: $e);
        }

        foreach ($collection->getTools() as $reference) {
            if ($this->canWrite('tool', $reference->tool->name, $registry->hasTool(...))) {
                $registry->registerTool($reference->tool, $reference->handler);
            }
        }

        foreach ($collection->getResources() as $reference) {
            if ($this->canWrite('resource', $reference->resource->uri, $registry->hasResource(...))) {
                $registry->registerResource($reference->resource, $reference->handler);
            }
        }

        foreach ($collection->getResourceTemplates() as $reference) {
            if ($this->canWrite('resource template', $reference->resourceTemplate->uriTemplate, $registry->hasResourceTemplate(...))) {
                $registry->registerResourceTemplate($reference->resourceTemplate, $reference->handler, $reference->completionProviders);
            }
        }

        foreach ($collection->getPrompts() as $reference) {
            if ($this->canWrite('prompt', $reference->prompt->name, $registry->hasPrompt(...))) {
                $registry->registerPrompt($reference->prompt, $reference->handler, $reference->completionProviders);
            }
        }
    }

    /**
     * @param callable(string): bool $exists
     */
    private function canWrite(string $kind, string $key, callable $exists): bool
    {
        if (!$this->onlyMissing || !$exists($key)) {
            return true;
        }

        $this->logger->debug(\sprintf('Ignoring %s "%s" from an element source: the registry already holds it.', $kind, $key));

        return false;
    }
}
