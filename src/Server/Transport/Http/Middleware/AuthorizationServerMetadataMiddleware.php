<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Server\Transport\Http\OAuth\AuthorizationServerMetadata;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves OAuth 2.0 Authorization Server Metadata (RFC 8414) at
 * /.well-known/oauth-authorization-server.
 *
 * Place this inside (after) {@see ClientRegistrationMiddleware} so the latter can
 * enrich the response with the registration_endpoint.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 */
final class AuthorizationServerMetadataMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly AuthorizationServerMetadata $metadata,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('GET' !== $request->getMethod() || $this->metadata->getMetadataPath() !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'max-age=3600')
            ->withBody($this->streamFactory->createStream(json_encode($this->metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
    }
}
