<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\CachedDiscoveryExample;

use Mcp\Capability\Attribute\McpTool;

/**
 * Example MCP elements for demonstrating cached discovery.
 *
 * This class contains simple calculator tools that will be discovered
 * and cached for improved performance on subsequent server starts.
 */
class CachedCalculatorElements
{
    #[McpTool(name: 'add_numbers')]
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    #[McpTool(name: 'multiply_numbers')]
    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }

    #[McpTool(name: 'divide_numbers')]
    public function divide(int $a, int $b): float
    {
        if (0 === $b) {
            throw new \InvalidArgumentException('Division by zero is not allowed');
        }

        return $a / $b;
    }

    #[McpTool(name: 'power')]
    public function power(int $base, int $exponent): int
    {
        return (int) $base ** $exponent;
    }
}
