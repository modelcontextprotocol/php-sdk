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

namespace Mcp\Tests\Capability\Discovery\Fixtures\SubDir;

use Mcp\Capability\Attribute\McpTool;

class HiddenTool
{
    #[McpTool(name: 'hidden_subdir_tool')]
    public function run()
    {
    }
}
