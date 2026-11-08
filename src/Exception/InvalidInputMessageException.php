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
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InvalidInputMessageException extends \InvalidArgumentException implements ExceptionInterface
{
    private string|int|null $requestId = null;

    private bool $invalidParams = false;

    /**
     * The message is a well-formed request whose params do not fit its method.
     */
    public static function forInvalidParams(string $message, ?\Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->invalidParams = true;

        return $exception;
    }

    public function isInvalidParams(): bool
    {
        return $this->invalidParams;
    }

    public function getRequestId(): string|int|null
    {
        return $this->requestId;
    }

    public function setRequestId(string|int|null $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }
}
