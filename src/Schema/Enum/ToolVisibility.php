<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Enum;

/**
 * Tool visibility values for MCP Apps.
 *
 * Controls who can see and invoke a tool linked to a UI resource.
 */
enum ToolVisibility: string
{
    /** Visible to and callable by the LLM agent. */
    case Model = 'model';

    /** Callable by the MCP App (HTML view) only, hidden from the model's tools/list. */
    case App = 'app';
}
