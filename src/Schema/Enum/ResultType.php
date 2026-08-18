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
 * Introduced in 2026-07-28, where a request can come back either finished or
 * asking for more input; without a discriminator the client would have to
 * infer which by probing for fields. Servers on this revision MUST send it.
 *
 * Earlier revisions have no such field, and a client receiving a result
 * without one MUST read it as {@see self::Complete} — which is what makes it
 * safe to emit unconditionally rather than only on the newer revision.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum ResultType: string
{
    /** The request finished; the result holds the final content. */
    case Complete = 'complete';

    /** The request needs more input before it can finish (MRTR). */
    case InputRequired = 'input_required';
}
