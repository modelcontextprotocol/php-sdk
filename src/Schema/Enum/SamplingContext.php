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

enum SamplingContext: string
{
    case NONE = 'none';
    case THIS_SERVER = 'thisServer';
    case ALL_SERVERS = 'allServers';
}
