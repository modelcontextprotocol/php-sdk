<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Tool;

use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
interface ToolCallerInterface
{
    /**
     * @throws ToolCallException     if the tool execution fails
     * @throws ToolNotFoundException if the tool is not found
     */
    public function call(CallToolRequest $request): CallToolResult;
}
