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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Wire\McpHeader;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Applies CORS headers to responses produced by the inner pipeline.
 *
 * By default no `Access-Control-Allow-Origin` header is set, which effectively
 * blocks cross-origin browser requests (secure-by-default). Configure
 * `$allowedOrigins` with a concrete list, or `['*']` to allow any origin.
 *
 * `Access-Control-Allow-Methods` and `Access-Control-Allow-Headers` are emitted
 * only on preflight responses (`OPTIONS` with an `Access-Control-Request-Method`
 * header), per the CORS specification. An `$allowedHeaders` entry ending in `*`
 * (e.g. `Mcp-Param-*`) allows each requested header with that prefix. Headers
 * already set by inner middleware are preserved — this middleware only adds
 * defaults when they are absent.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class CorsMiddleware implements MiddlewareInterface
{
    private readonly bool $isWildcard;
    private readonly bool $varyOnOrigin;
    private readonly string $allowedMethodsHeader;
    /** @var list<string> */
    private readonly array $allowedHeaders;
    /** @var list<string> lowercased prefixes of the `*`-suffixed entries */
    private readonly array $allowedHeaderPrefixes;
    private readonly ?string $exposedHeadersHeader;

    /**
     * @param list<string> $allowedOrigins   Origins permitted for cross-origin requests. Empty disables `Access-Control-Allow-Origin`. Use `['*']` to allow any origin.
     * @param list<string> $allowedMethods   Methods advertised via `Access-Control-Allow-Methods` (preflight only)
     * @param list<string> $allowedHeaders   Headers advertised via `Access-Control-Allow-Headers` (preflight only); an entry ending in `*` matches requested headers by prefix
     * @param list<string> $exposedHeaders   Headers advertised via `Access-Control-Expose-Headers`
     * @param bool         $allowCredentials Whether to emit `Access-Control-Allow-Credentials: true`. Incompatible with `allowedOrigins: ['*']` — combining them throws.
     */
    public function __construct(
        private readonly array $allowedOrigins = [],
        array $allowedMethods = ['GET', 'POST', 'DELETE'],
        array $allowedHeaders = [
            'Accept',
            'Authorization',
            'Content-Type',
            'Last-Event-ID',
            StreamableHttpTransport::PROTOCOL_VERSION_HEADER,
            StreamableHttpTransport::SESSION_HEADER,
            McpHeader::METHOD,
            McpHeader::NAME,
            McpHeader::PARAM_PREFIX.'*',
        ],
        array $exposedHeaders = [StreamableHttpTransport::SESSION_HEADER, 'WWW-Authenticate'],
        private readonly bool $allowCredentials = false,
    ) {
        $this->isWildcard = \in_array('*', $allowedOrigins, true);

        if ($this->isWildcard && $allowCredentials) {
            throw new InvalidArgumentException('Access-Control-Allow-Origin: * is incompatible with Access-Control-Allow-Credentials: true. Configure an explicit allowedOrigins list when credentialed requests are required.');
        }

        $this->varyOnOrigin = [] !== $allowedOrigins && !$this->isWildcard;
        $this->allowedMethodsHeader = implode(', ', $allowedMethods);

        $headers = [];
        $prefixes = [];
        foreach ($allowedHeaders as $header) {
            if ('*' !== $header && str_ends_with($header, '*')) {
                $prefixes[] = strtolower(substr($header, 0, -1));
            } else {
                $headers[] = $header;
            }
        }
        $this->allowedHeaders = $headers;
        $this->allowedHeaderPrefixes = $prefixes;
        $this->exposedHeadersHeader = [] === $exposedHeaders ? null : implode(', ', $exposedHeaders);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $allowedOrigin = $this->resolveAllowedOrigin($request->getHeaderLine('Origin'));
        if (null !== $allowedOrigin && !$response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        if ($this->allowCredentials && null !== $allowedOrigin && !$response->hasHeader('Access-Control-Allow-Credentials')) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->varyOnOrigin) {
            $response = $this->ensureVaryOrigin($response);
        }

        if ($this->isPreflight($request)) {
            if (!$response->hasHeader('Access-Control-Allow-Methods')) {
                $response = $response->withHeader('Access-Control-Allow-Methods', $this->allowedMethodsHeader);
            }

            if (!$response->hasHeader('Access-Control-Allow-Headers')) {
                $response = $response->withHeader('Access-Control-Allow-Headers', implode(', ', $this->resolveAllowedHeaders($request)));
            }
        }

        if (null !== $this->exposedHeadersHeader && !$response->hasHeader('Access-Control-Expose-Headers')) {
            $response = $response->withHeader('Access-Control-Expose-Headers', $this->exposedHeadersHeader);
        }

        return $response;
    }

    private function isPreflight(ServerRequestInterface $request): bool
    {
        return 'OPTIONS' === $request->getMethod()
            && $request->hasHeader('Access-Control-Request-Method');
    }

    /**
     * The configured headers plus each requested one matching a prefix pattern.
     * `Access-Control-Allow-Headers` has no prefix syntax, so a pattern like
     * `Mcp-Param-*` has to be answered with the concrete names the browser asked for.
     *
     * @return list<string>
     */
    private function resolveAllowedHeaders(ServerRequestInterface $request): array
    {
        $headers = $this->allowedHeaders;
        if ([] === $this->allowedHeaderPrefixes) {
            return $headers;
        }

        foreach (explode(',', $request->getHeaderLine('Access-Control-Request-Headers')) as $requested) {
            $requested = trim($requested);
            foreach ($this->allowedHeaderPrefixes as $prefix) {
                if ('' !== $requested && str_starts_with(strtolower($requested), $prefix)) {
                    $headers[] = $requested;
                    break;
                }
            }
        }

        return $headers;
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

        if ('*' === trim($current)) {
            return $response;
        }

        $tokens = array_map('strtolower', array_map('trim', explode(',', $current)));
        if (\in_array('origin', $tokens, true)) {
            return $response;
        }

        return $response->withHeader('Vary', $current.', Origin');
    }
}
