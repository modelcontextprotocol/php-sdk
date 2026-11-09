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

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Thrown when a client cannot obtain the credentials a protected MCP server demands.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class AuthorizationException extends \RuntimeException implements ExceptionInterface, ClientExceptionInterface
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        // A message can quote a server, which must not write to the terminal or the log that shows it.
        parent::__construct((string) preg_replace('/(?:[\x00-\x1F\x7F]|\xC2[\x80-\x9F])+/', ' ', $message), $code, $previous);
    }
}
