<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Capability\Attribute;

use Attribute;

/**
 * Marks a PHP method as an MCP Prompt generator.
 * The method should return the prompt messages, potentially using arguments for templating.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class McpPrompt
{
    /**
     * @param ?string $name        overrides the prompt name (defaults to method name)
     * @param ?string $description Optional description of the prompt. Defaults to method DocBlock summary.
     */
    public function __construct(
        public ?string $name = null,
        public ?string $description = null,
    ) {
    }
}
