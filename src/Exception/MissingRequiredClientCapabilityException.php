<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

use Mcp\Schema\ClientCapabilities;

/**
 * Answering the request needs a client capability the request never declared.
 *
 * In the handshake era a server learned the client's capabilities once, at
 * `initialize`, and could refuse to advertise features the client could not
 * use. A modern request restates its capabilities every time, so the check
 * moves to call time — a handler that needs to call back into the client
 * throws this, and the protocol answers `-32021` with HTTP 400.
 *
 * The capabilities travel as a {@see ClientCapabilities} object rather than a
 * list of names so the client can compare them against what it would send.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class MissingRequiredClientCapabilityException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly ClientCapabilities $requiredCapabilities,
        string $message = 'Request requires a client capability that was not declared.',
    ) {
        parent::__construct($message);
    }
}
