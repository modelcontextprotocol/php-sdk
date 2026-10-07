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
 * Whether a tool can be called as a task (task-augmented execution).
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/utilities/tasks
 */
enum TaskSupport: string
{
    /** The tool cannot be called as a task. The default. */
    case Forbidden = 'forbidden';

    /** The tool may be called either way. */
    case Optional = 'optional';

    /** The tool must be called as a task. */
    case Required = 'required';
}
