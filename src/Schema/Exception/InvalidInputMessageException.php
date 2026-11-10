<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Exception;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class InvalidInputMessageException extends InvalidArgumentException
{
    private string|int|null $requestId = null;

    private ?string $invalidParamsMethod = null;

    /**
     * The message is a well-formed request whose params do not fit its method.
     */
    public static function forInvalidParams(string $message, string $method, ?\Throwable $previous = null): self
    {
        $exception = new self($message, 0, $previous);
        $exception->invalidParamsMethod = $method;

        return $exception;
    }

    public function isInvalidParams(): bool
    {
        return null !== $this->invalidParamsMethod;
    }

    public function isInvalidParamsOf(string $method): bool
    {
        return $method === $this->invalidParamsMethod;
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
