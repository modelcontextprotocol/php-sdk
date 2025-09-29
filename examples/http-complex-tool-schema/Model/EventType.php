<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\HttpComplexToolSchema\Model;

enum EventType: string
{
    case Meeting = 'meeting';
    case Reminder = 'reminder';
    case Call = 'call';
    case Other = 'other';
}
