<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\RequestHandler\Reference;

/**
 * @implements \ArrayAccess<int|string, mixed>
 */
final class Page implements \Countable, \ArrayAccess
{
    /**
     * @param array<int|string, mixed> $references Items can be Tool, Prompt, ResourceTemplate, or Resource
     */
    public function __construct(
        public readonly array $references,
        public readonly ?string $nextCursor,
    ) {
    }

    public function count(): int
    {
        return \count($this->references);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->references[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->references[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        return;
    }

    public function offsetUnset(mixed $offset): void
    {
        return;
    }
}
