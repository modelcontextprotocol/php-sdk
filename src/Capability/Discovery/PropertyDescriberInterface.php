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
 * Translates a PHP class type into a JSON Schema fragment.
 *
 * The {@see SchemaGenerator} consults registered describers, in order, before
 * falling back to generic class inspection. The first describer that returns
 * a non-null schema wins. Implementations let callers teach the generator
 * about value-object types (DateTime, Uuid, etc.) whose JSON Schema
 * representation is more specific than a generic `{type: "object"}`.
 */
interface PropertyDescriberInterface
{
    /**
     * @param class-string $className
     *
     * @return array<string, mixed>|null Schema fragment, or null to pass to the next describer
     */
    public function describe(string $className): ?array;
}
