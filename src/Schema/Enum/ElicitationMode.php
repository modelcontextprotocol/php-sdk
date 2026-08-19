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
 * How the client should collect the information an elicitation asks for.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum ElicitationMode: string
{
    /**
     * Present a form built from the requested schema, and return the filled values.
     */
    case Form = 'form';

    /**
     * Send the user to a URL to complete the interaction out of band. The result
     * carries no content — only whether the user accepted, declined, or cancelled.
     */
    case Url = 'url';
}
