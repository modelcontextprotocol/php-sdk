<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves OAuth 2.0 Protected Resource Metadata (RFC 9728) as a standalone request handler.
 *
 * This is a plain PSR-15 {@see RequestHandlerInterface} — the "controller" that decides *what*
 * to return, independent of *when*. It can be used three ways from a single instance:
 *
 *  - inside the MCP transport, wrapped by {@see \Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware};
 *  - as a bare PSR-7 handler in a hand-rolled front controller;
 *  - as a framework callable controller (Symfony/Laravel), by converting the framework
 *    request to PSR-7 and the returned PSR-7 response back — see docs/authorization.md.
 *
 * It performs no path or method matching: routing is the caller's responsibility (the
 * middleware's guard, or the framework router).
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtectedResourceMetadataHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly ProtectedResourceMetadata $metadata,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream(json_encode($this->metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
    }
}
