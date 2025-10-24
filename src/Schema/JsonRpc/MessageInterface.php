<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\JsonRpc;

use Mcp\Schema\Enum\ProtocolVersion;

/**
 * Refers to any valid JSON-RPC object that can be decoded off the wire, or encoded to be sent.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface MessageInterface extends \JsonSerializable
{
    public const JSONRPC_VERSION = '2.0';
    public const PROTOCOL_VERSION = ProtocolVersion::V2025_06_18;
}
