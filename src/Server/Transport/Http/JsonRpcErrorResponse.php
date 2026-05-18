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
 * Builds a PSR-7 response with the given HTTP status and a JSON-RPC
 * `Error` payload as body. Caller decides which `Error::for*` factory
 * to use so the JSON-RPC error code matches the failure semantics.
 *
 * @internal
 */
final class JsonRpcErrorResponse
{
    public static function create(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        int $statusCode,
        Error $error,
    ): ResponseInterface {
        $body = json_encode($error, \JSON_THROW_ON_ERROR);

        return $responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($streamFactory->createStream($body));
    }
}
