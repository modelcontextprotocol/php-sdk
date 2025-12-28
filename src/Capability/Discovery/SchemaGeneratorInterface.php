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
 * Implementations can use different strategies to generate schemas:
 * - Reflection-based (types and docblocks)
 * - Attribute-based (Schema attributes)
 * - External libraries (API Platform, etc.)
 * - Class-based metadata
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface SchemaGeneratorInterface
{
    /**
     * Generates a JSON Schema for input parameters.
     *
     * The returned schema must be a valid JSON Schema object (type: 'object')
     * with properties corresponding to parameters.
     *
     * - For ReflectionMethod/ReflectionFunction: schema based on method parameters
     * - For ReflectionClass: schema based on __construct, __invoke parameters,
     *   or class properties/metadata
     *
     * @return array{
     *     type: 'object',
     *     properties: array<string, mixed>|object,
     *     required?: string[]
     * }
     */
    public function generate(\Reflector $reflection): array;
}
