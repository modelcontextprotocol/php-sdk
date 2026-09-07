<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

/**
 * @template TItem of Tool|Prompt|ResourceTemplate|ResourceDefinition
 *
 * @extends \ArrayObject<int|string, TItem>
 */
final class Page extends \ArrayObject
{
    /**
     * @param array<int|string, TItem> $references Items can be Tool, Prompt, ResourceTemplate, or ResourceDefinition
     */
    public function __construct(
        public readonly array $references,
        public readonly ?string $nextCursor,
    ) {
        parent::__construct($references, \ArrayObject::ARRAY_AS_PROPS);
    }

    public function count(): int
    {
        return \count($this->references);
    }
}
