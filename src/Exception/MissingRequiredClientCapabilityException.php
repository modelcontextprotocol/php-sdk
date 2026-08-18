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
 * Answering the request needs a client capability it never declared; the
 * protocol answers `-32021` with HTTP 400.
 *
 * The capabilities travel as a {@see ClientCapabilities} object rather than a
 * list of names, so the client can compare them against what it would send.
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
