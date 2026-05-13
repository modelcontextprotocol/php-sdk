<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http;

use Mcp\Schema\JsonRpc\Error;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the canonical JSON-RPC error response used by the HTTP transport
 * and its middleware: a PSR-7 response with the given HTTP status, a
 * `Content-Type: application/json` header, and a body containing a single
 * `Error::forInvalidRequest($message)` payload.
 *
 * @internal
 */
final class JsonRpcErrorResponse
{
    public static function create(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        int $statusCode,
        string $message,
    ): ResponseInterface {
        $body = json_encode(Error::forInvalidRequest($message), \JSON_THROW_ON_ERROR);

        return $responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream($body));
    }
}
