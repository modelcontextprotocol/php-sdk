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
 * Tells the client how to read a result before it looks at the body.
 *
 * Required from 2026-07-28, where a request may come back finished or asking
 * for input. A client seeing no `resultType` MUST read it as
 * {@see self::Complete}.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum ResultType: string
{
    /** The request finished; the result holds the final content. */
    case Complete = 'complete';

    /** The request needs more input before it can finish (MRTR). */
    case InputRequired = 'input_required';

    /** The request became a task; the result is the handle to poll (Tasks extension). */
    case Task = 'task';
}
