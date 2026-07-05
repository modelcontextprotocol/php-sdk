<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthResourceMetadata;

use Mcp\Capability\Attribute\McpTool;

final class McpElements
{
    /**
     * Returns a friendly greeting.
     *
     * @param string $name the name to greet
     *
     * @return string the greeting
     */
    #[McpTool(name: 'greet')]
    public function greet(string $name): string
    {
        return \sprintf('Hello, %s!', $name);
    }
}
