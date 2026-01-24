<?php

declare(strict_types=1);

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
