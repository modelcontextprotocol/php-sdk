<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The namespace deliberately does not match this file's path, so PSR-4 cannot
 * autoload the class and discovery has to skip the file. See
 * {@see \Mcp\Tests\Unit\Capability\Discovery\DiscoveryTest}.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\NotWhereThisFileLives;

use Mcp\Capability\Attribute\McpTool;

final class OrphanedHandler
{
    #[McpTool(name: 'orphaned_tool')]
    public function orphaned(): string
    {
        return 'never discovered';
    }
}
