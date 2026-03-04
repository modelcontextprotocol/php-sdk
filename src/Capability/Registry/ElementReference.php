<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

/**
 * @phpstan-type Handler \Closure|array{0: object|string, 1: string}|string
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ElementReference
{
    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly \Closure|array|string $handler,
        public readonly bool $isManual = false,
        public readonly bool $isDiscovered = false,
    ) {
    }
}
