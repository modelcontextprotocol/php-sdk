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
 * The sender or recipient of messages and data in a conversation.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
enum Role: string
{
    case Assistant = 'assistant';
    case User = 'user';
}
