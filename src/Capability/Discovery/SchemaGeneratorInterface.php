<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Schema\Tool;

/**
 * Provides JSON Schema generation for reflected elements.
 *
 * @phpstan-import-type ToolInputSchema from Tool
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface SchemaGeneratorInterface
{
    /**
     * Generates a JSON Schema for input parameters.
     *
     * The returned schema must be a valid JSON Schema object (type: 'object')
     * with properties corresponding to a tool's parameters.
     *
     * @return ToolInputSchema
     */
    public function generate(\Reflector $reflection): array;

    /**
     * Generates a JSON Schema for output/result.
     *
     * @return ?array<string, mixed>
     */
    public function generateOutputSchema(\Reflector $reflection): ?array;
}
