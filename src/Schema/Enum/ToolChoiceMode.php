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
 * How the model may use the tools offered on a sampling request.
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case Required = 'required';
    case None = 'none';
}
