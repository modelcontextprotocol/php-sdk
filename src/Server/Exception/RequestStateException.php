<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Exception;

/**
 * An echoed `requestState` failed verification.
 *
 * The message is a bare reason code — `malformed`, `mac` or `expired` —
 * because whoever sent the value may be probing.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class RequestStateException extends InvalidArgumentException
{
}
