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

namespace Mcp\Schema\Content;

use JsonSerializable;

/**
 * Base class for all content types in MCP.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class Content implements JsonSerializable
{
    public function __construct(
        public readonly string $type,
    ) {
    }
}
