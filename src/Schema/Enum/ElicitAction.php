<?php

declare(strict_types=1);

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
 * Action taken by the user in response to an elicitation request.
 *
 * @author
 */
enum ElicitAction: string
{
    case Accept = 'accept';
    case Decline = 'decline';
    case Cancel = 'cancel';
}
