<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\LeadingEnumFixtures;

use Mcp\Capability\Attribute\McpTool;

enum LeadingEnumMode: string
{
    case Fast = 'fast';
}

class LeadingEnumToolHandler
{
    #[McpTool(name: 'after_enum')]
    public function run(LeadingEnumMode $mode): string
    {
        return $mode->value;
    }
}
