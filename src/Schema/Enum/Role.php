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

namespace Mcp\Schema\Enum;

/**
 * The sender or recipient of messages and data in a conversation.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
