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

final class ClientRegistrationException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'invalid_client_metadata',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
