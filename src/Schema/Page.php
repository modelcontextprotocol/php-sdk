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
 * @phpstan-type PageItem Tool|Prompt|ResourceTemplate|Resource
 *
 * @extends \ArrayObject<int|string, PageItem>
 */
final class Page extends \ArrayObject
{
    /**
     * @param array<int|string, PageItem> $references Items can be Tool, Prompt, ResourceTemplate, or Resource
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
