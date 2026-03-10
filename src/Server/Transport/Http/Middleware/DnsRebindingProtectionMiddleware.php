<?php

declare(strict_types=1);

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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects against DNS rebinding attacks by validating Host and Origin headers.
 *
 * Rejects requests where the Host or Origin header points to a non-allowed hostname.
 * By default, only localhost variants (localhost, 127.0.0.1, [::1]) are allowed.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices#local-mcp-server-compromise
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#security-warning
 */
final class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;

    /**
     * @param string[]                      $allowedHosts    Allowed hostnames (without port). Defaults to localhost variants.
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 response factory
     */
    public function __construct(
        private readonly array $allowedHosts = ['localhost', '127.0.0.1', '[::1]', '::1'],
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $host = $request->getHeaderLine('Host');
        if ('' !== $host && !$this->isAllowedHost($host)) {
            return $this->createForbiddenResponse('Forbidden: Invalid Host header.');
        }

        $origin = $request->getHeaderLine('Origin');
        if ('' !== $origin && !$this->isAllowedOrigin($origin)) {
            return $this->createForbiddenResponse('Forbidden: Invalid Origin header.');
        }

        return $handler->handle($request);
    }

    private function isAllowedHost(string $hostHeader): bool
    {
        // Strip port from Host header (e.g., "localhost:8000" -> "localhost")
        $host = strtolower(preg_replace('/:\d+$/', '', $hostHeader) ?? $hostHeader);

        return \in_array($host, $this->allowedHosts, true);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $parsed = parse_url($origin);
        if (false === $parsed || !isset($parsed['host'])) {
            return false;
        }

        return \in_array(strtolower($parsed['host']), $this->allowedHosts, true);
    }

    private function createForbiddenResponse(string $message): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write($message);

        return $response;
    }
}
