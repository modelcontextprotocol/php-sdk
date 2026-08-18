<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

/**
 * A modern-era request omitted metadata the server structurally needs to
 * answer it — the protocol version or the client's capabilities.
 *
 * Answered with a JSON-RPC `-32602 Invalid params` error and HTTP 400.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class MissingRequestMetaException extends InvalidArgumentException
{
}
