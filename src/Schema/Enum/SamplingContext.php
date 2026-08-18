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
 * Which servers' context a sampling request asks the client to attach.
 *
 * `thisServer` and `allServers` are themselves deprecated since 2025-11-25
 * (SEP-2596): omit the field or use `none`.
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
enum SamplingContext: string
{
    case NONE = 'none';
    case THIS_SERVER = 'thisServer';
    case ALL_SERVERS = 'allServers';
}
