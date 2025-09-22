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

use Mcp\Schema\JsonRpc\Error;

class RegistryException extends \Exception implements ExceptionInterface
{
    public static function invalidParams(string $message = 'Invalid params', ?\Throwable $previous = null): self
    {
        return new self($message, Error::INVALID_PARAMS, $previous);
    }

    public static function internalError(?string $details = null, ?\Throwable $previous = null): self
    {
        $message = 'Internal error';
        if (null !== $details) {
            $message .= ': '.$details;
        }
        if (null !== $previous) {
            $message .= ' (See server logs)';
        }

        return new self($message, Error::INTERNAL_ERROR, $previous);
    }
}
