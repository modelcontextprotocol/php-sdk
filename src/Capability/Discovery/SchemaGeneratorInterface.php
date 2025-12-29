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

/**
 * Provides JSON Schema generation for reflected elements.
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
     * @return array{
     *     type: 'object',
     *     properties: array<string, mixed>|object,
     *     required?: string[]
     * }
     */
    public function generate(\Reflector $reflection): array;
}
