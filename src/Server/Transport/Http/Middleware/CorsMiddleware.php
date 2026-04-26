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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies CORS headers to all responses.
 *
 * By default, no Access-Control-Allow-Origin header is set, which effectively
 * blocks cross-origin browser requests. Configure $allowedOrigins to allow
 * specific origins or use ['*'] to allow all.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $allowedOrigins Origins to allow (empty = no Access-Control-Allow-Origin header). Use ['*'] to allow all origins.
     * @param list<string> $allowedMethods HTTP methods for Access-Control-Allow-Methods
     * @param list<string> $allowedHeaders Request headers for Access-Control-Allow-Headers
     * @param list<string> $exposedHeaders Response headers for Access-Control-Expose-Headers
     */
    public function __construct(
        private readonly array $allowedOrigins = [],
        private readonly array $allowedMethods = ['GET', 'POST', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = ['Accept', 'Authorization', 'Content-Type', 'Last-Event-ID', 'Mcp-Protocol-Version', 'Mcp-Session-Id'],
        private readonly array $exposedHeaders = ['Mcp-Session-Id'],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $origin = $request->getHeaderLine('Origin');
        $allowedOrigin = $this->resolveAllowedOrigin($origin);

        if (null !== $allowedOrigin && !$response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        if (!$response->hasHeader('Access-Control-Allow-Methods')) {
            $response = $response->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods));
        }

        if (!$response->hasHeader('Access-Control-Allow-Headers')) {
            $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders));
        }

        if ([] !== $this->exposedHeaders && !$response->hasHeader('Access-Control-Expose-Headers')) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        return $response;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ([] === $this->allowedOrigins) {
            return null;
        }

        if (\in_array('*', $this->allowedOrigins, true)) {
            return '*';
        }

        if ('' !== $origin && \in_array($origin, $this->allowedOrigins, true)) {
            return $origin;
        }

        return null;
    }
}
