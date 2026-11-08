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
 * The params of a request do not fit the method, e.g. a required one is missing.
 *
 * The request itself is well-formed, so it is answered with `-32602` (Invalid params)
 * and not with `-32600` (Invalid request).
 */
class InvalidParamsException extends InvalidArgumentException
{
}
