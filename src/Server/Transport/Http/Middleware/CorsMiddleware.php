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

use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies CORS headers to all responses produced by the inner pipeline.
 *
 * By default no `Access-Control-Allow-Origin` header is set, which effectively
 * blocks cross-origin browser requests (secure-by-default). Configure
 * `$allowedOrigins` with a concrete list, or `['*']` to allow any origin.
 *
 * Headers already set by inner middleware are preserved — this middleware only
 * adds defaults when they are absent.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private readonly bool $isWildcard;
    private readonly bool $varyOnOrigin;
    private readonly string $allowedMethodsHeader;
    private readonly string $allowedHeadersHeader;
    private readonly ?string $exposedHeadersHeader;

    /**
     * @param list<string> $allowedOrigins Origins permitted for cross-origin requests. Empty disables `Access-Control-Allow-Origin`. Use `['*']` to allow any origin.
     * @param list<string> $allowedMethods Methods advertised via `Access-Control-Allow-Methods`
     * @param list<string> $allowedHeaders Headers advertised via `Access-Control-Allow-Headers`
     * @param list<string> $exposedHeaders Headers advertised via `Access-Control-Expose-Headers`
     */
    public function __construct(
        private readonly array $allowedOrigins = [],
        array $allowedMethods = ['GET', 'POST', 'DELETE', 'OPTIONS'],
        array $allowedHeaders = [
            'Accept',
            'Authorization',
            'Content-Type',
            'Last-Event-ID',
            StreamableHttpTransport::PROTOCOL_VERSION_HEADER,
            StreamableHttpTransport::SESSION_HEADER,
        ],
        array $exposedHeaders = [StreamableHttpTransport::SESSION_HEADER],
    ) {
        $this->isWildcard = \in_array('*', $allowedOrigins, true);
        $this->varyOnOrigin = [] !== $allowedOrigins && !$this->isWildcard;
        $this->allowedMethodsHeader = implode(', ', $allowedMethods);
        $this->allowedHeadersHeader = implode(', ', $allowedHeaders);
        $this->exposedHeadersHeader = [] === $exposedHeaders ? null : implode(', ', $exposedHeaders);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $allowedOrigin = $this->resolveAllowedOrigin($request->getHeaderLine('Origin'));
        if (null !== $allowedOrigin && !$response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        if ($this->varyOnOrigin) {
            $response = $this->ensureVaryOrigin($response);
        }

        if (!$response->hasHeader('Access-Control-Allow-Methods')) {
            $response = $response->withHeader('Access-Control-Allow-Methods', $this->allowedMethodsHeader);
        }

        if (!$response->hasHeader('Access-Control-Allow-Headers')) {
            $response = $response->withHeader('Access-Control-Allow-Headers', $this->allowedHeadersHeader);
        }

        if (null !== $this->exposedHeadersHeader && !$response->hasHeader('Access-Control-Expose-Headers')) {
            $response = $response->withHeader('Access-Control-Expose-Headers', $this->exposedHeadersHeader);
        }

        return $response;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ([] === $this->allowedOrigins) {
            return null;
        }

        if ($this->isWildcard) {
            return '*';
        }

        if ('' !== $origin && \in_array($origin, $this->allowedOrigins, true)) {
            return $origin;
        }

        return null;
    }

    private function ensureVaryOrigin(ResponseInterface $response): ResponseInterface
    {
        $current = $response->getHeaderLine('Vary');

        if ('' === $current) {
            return $response->withHeader('Vary', 'Origin');
        }

        if ('*' === trim($current) || false !== stripos($current, 'origin')) {
            return $response;
        }

        return $response->withHeader('Vary', $current.', Origin');
    }
}
