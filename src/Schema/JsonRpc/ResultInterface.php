<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\JsonRpc;

use JsonSerializable;

/**
 * Base class for all specific MCP result objects (the value of the 'result' field).
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface ResultInterface extends JsonSerializable
{
}
