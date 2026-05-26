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
 * A describer declares the class (or base class/interface) it handles via
 * {@see PropertyHandlerInterface::supportedClass()}. The {@see SchemaGenerator}
 * matches a parameter's or return type's concrete class against that type —
 * directly or through its parents and interfaces — and, when several describers
 * are registered, consults them in priority order. Implementations let callers
 * teach the generator about value-object types (DateTime, Uuid, etc.) whose JSON
 * Schema representation is more specific than a generic `{type: "object"}`.
 */
interface PropertyDescriberInterface extends PropertyHandlerInterface
{
    /**
     * @return array<string, mixed> Schema fragment for the supported type
     */
    public function describe(): array;
}
