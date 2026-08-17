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
 * Thrown when the server answers a request with a non-success HTTP status
 * code whose body is not a JSON-RPC error response. Carries the status code
 * and a snippet of the response body so callers can surface the server-side
 * failure instead of waiting on a timeout.
 */
class HttpTransportException extends ConnectionException
{
    private readonly int $statusCode;

    public function __construct(string $message, int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
