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

/**
 * Exception thrown when an elicitation request fails.
 *
 * When thrown from an elicitation callback, this exception's message will be
 * included in the error response sent back to the server.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ElicitationException extends \RuntimeException implements ExceptionInterface
{
}
