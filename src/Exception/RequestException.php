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

/**
 * Exception for MCP request failures.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class RequestException extends Exception
{
    private ?Error $error;

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, ?Error $error = null)
    {
        parent::__construct($message, $code, $previous);
        $this->error = $error;
    }

    public static function fromError(Error $error): self
    {
        return new self($error->message, $error->code, null, $error);
    }

    public function getError(): ?Error
    {
        return $this->error;
    }
}
