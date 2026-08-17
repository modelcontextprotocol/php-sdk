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
 * @deprecated Deprecated as of protocol revision 2026-07-28 (SEP-2577). Still functional for at
 * least twelve months. Integrate with an LLM provider's API directly instead.
 */
enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case Required = 'required';
    case None = 'none';
}
