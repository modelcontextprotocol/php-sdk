<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Provider\Fixtures;

use Mcp\Capability\Provider\DynamicResourceProviderInterface;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Resource;

/**
 * Test fixture for DynamicResourceProviderInterface.
 *
 * This class provides a simple implementation for testing dynamic resource providers.
 */
final class TestDynamicResourceProvider implements DynamicResourceProviderInterface
{
    /**
     * @param array<resource> $resources
     */
    public function __construct(
        private readonly array $resources = [],
    ) {
    }

    public function getResources(): iterable
    {
        return $this->resources;
    }

    public function supportsResource(string $uri): bool
    {
        foreach ($this->resources as $resource) {
            if ($resource->uri === $uri) {
                return true;
            }
        }

        return false;
    }

    public function readResource(string $uri): mixed
    {
        foreach ($this->resources as $resource) {
            if ($resource->uri === $uri) {
                return [
                    new TextResourceContents(
                        uri: $uri,
                        mimeType: $resource->mimeType ?? 'text/plain',
                        text: "Content of resource {$uri}",
                    ),
                ];
            }
        }

        throw new \RuntimeException("Resource {$uri} not found");
    }
}
