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

use Mcp\Capability\Provider\DynamicResourceTemplateProviderInterface;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\ResourceTemplate;

/**
 * Test fixture for DynamicResourceTemplateProviderInterface.
 *
 * This class provides a simple implementation for testing dynamic resource template providers.
 */
final class TestDynamicResourceTemplateProvider implements DynamicResourceTemplateProviderInterface
{
    /**
     * @param array<ResourceTemplate> $templates
     */
    public function __construct(
        private readonly array $templates = [],
    ) {
    }

    public function getResourceTemplates(): iterable
    {
        return $this->templates;
    }

    public function supportsResourceTemplate(string $uriTemplate): bool
    {
        foreach ($this->templates as $template) {
            if ($template->uriTemplate === $uriTemplate) {
                return true;
            }
        }

        return false;
    }

    public function readResource(string $uriTemplate, string $uri): mixed
    {
        foreach ($this->templates as $template) {
            if ($template->uriTemplate === $uriTemplate) {
                return [
                    new TextResourceContents(
                        uri: $uri,
                        mimeType: $template->mimeType ?? 'text/plain',
                        text: "Content from template {$uriTemplate} for URI {$uri}",
                    ),
                ];
            }
        }

        throw new \RuntimeException("Resource template {$uriTemplate} not found");
    }

    public function getCompletionProviders(string $uriTemplate): array
    {
        return [];
    }
}
