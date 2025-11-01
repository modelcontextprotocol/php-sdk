<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Attribute;

/**
 * Marks a PHP method as an MCP Prompt generator.
 * The method should return the prompt messages, potentially using arguments for templating.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class McpPrompt
{
    /**
     * @param ?string               $name        overrides the prompt name (defaults to method name)
     * @param ?string               $description Optional description of the prompt. Defaults to method DocBlock summary.
     * @param ?array<string, mixed> $_meta       Optional metadata
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
        public ?array $_meta = null,
    ) {
    }
}
