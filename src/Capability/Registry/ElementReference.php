<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Capability\Registry;

use Closure;

/**
 * @phpstan-type Handler Closure|array{0: object|string, 1: string}|string
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ElementReference
{
    /**
     * @param Handler $handler
     */
    public function __construct(
        public readonly Closure|array|string $handler,
        public readonly bool $isManual = false,
    ) {
    }
}
