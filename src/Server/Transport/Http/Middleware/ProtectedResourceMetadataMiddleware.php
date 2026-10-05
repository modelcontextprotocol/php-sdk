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

use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadataHandler;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves OAuth 2.0 Protected Resource Metadata (RFC 9728) at its well-known path.
 *
 * This is a thin path-guard adapter: it decides *when* the metadata endpoint applies
 * (a GET to the metadata path derived from the resource identifier) and delegates the *what* to
 * {@see ProtectedResourceMetadataHandler}, the reusable request handler that can also be
 * mounted directly as a framework controller.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc9728
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtectedResourceMetadataMiddleware implements MiddlewareInterface
{
    private ProtectedResourceMetadataHandler $metadataHandler;

    public function __construct(
        private readonly ProtectedResourceMetadata $metadata,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->metadataHandler = new ProtectedResourceMetadataHandler($metadata, $responseFactory, $streamFactory);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isMetadataRequest($request)) {
            return $handler->handle($request);
        }

        return $this->metadataHandler->handle($request);
    }

    private function isMetadataRequest(ServerRequestInterface $request): bool
    {
        return 'GET' === $request->getMethod()
            && $request->getUri()->getPath() === $this->metadata->getMetadataPath();
    }
}
