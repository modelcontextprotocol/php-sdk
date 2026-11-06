<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tools\SchemaGenerator\Model;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PropertyDefinition
{
    /**
     * @param list<string> $revisions revisions that know this property, empty if all revisions of its type do
     */
    public function __construct(
        public readonly string $wireName,
        public readonly string $phpName,
        public readonly TypeRef $type,
        public readonly bool $required,
        public readonly ?string $deprecated = null,
        public readonly array $revisions = [],
        public readonly ?string $transform = null,
    ) {
    }
}
