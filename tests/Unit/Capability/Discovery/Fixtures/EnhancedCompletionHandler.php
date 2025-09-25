<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Tests\Unit\Fixtures\Enum\PriorityEnum;
use Mcp\Tests\Unit\Fixtures\Enum\StatusEnum;

class EnhancedCompletionHandler
{
    /**
     * Create content with list and enum completion providers.
     */
    #[McpPrompt(name: 'content_creator')]
    public function createContent(
        #[CompletionProvider(values: ['blog', 'article', 'tutorial', 'guide'])]
        string $type,
        #[CompletionProvider(enum: StatusEnum::class)]
        string $status,
        #[CompletionProvider(enum: PriorityEnum::class)]
        string $priority,
    ): array {
        return [
            ['role' => 'user', 'content' => "Create a {$type} with status {$status} and priority {$priority}"],
        ];
    }

    /**
     * Resource template with list completion for categories.
     */
    #[McpResourceTemplate(
        uriTemplate: 'content://{category}/{slug}',
        name: 'content_template'
    )]
    public function getContent(
        #[CompletionProvider(values: ['news', 'blog', 'docs', 'api'])]
        string $category,
        string $slug,
    ): array {
        return [
            'category' => $category,
            'slug' => $slug,
            'url' => "https://example.com/{$category}/{$slug}",
        ];
    }
}
