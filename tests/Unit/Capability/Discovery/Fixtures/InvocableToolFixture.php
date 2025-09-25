<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\McpTool;

#[McpTool(name: 'InvokableCalculator', description: 'An invokable calculator tool.')]
class InvocableToolFixture
{
    /**
     * Adds two numbers.
     */
    public function __invoke(int $a, int $b): int
    {
        return $a + $b;
    }
}
