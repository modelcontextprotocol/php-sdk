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
use Mcp\Schema\JsonRpc\Error;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects against DNS rebinding attacks by validating Origin and Host headers.
 *
 * When an Origin header is present, it is validated against the allowed hostnames.
 * Otherwise, the Host header is validated instead.
 * By default, only localhost variants (localhost, 127.0.0.1, [::1], ::1) are allowed.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices#local-mcp-server-compromise
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#security-warning
 */
final class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    private readonly array $allowedHosts;

    /**
     * @param string[]                      $allowedHosts    Allowed hostnames (without port). Defaults to localhost variants.
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 response factory
     * @param StreamFactoryInterface|null   $streamFactory   PSR-17 stream factory
     */
    public function __construct(
        array $allowedHosts = ['localhost', '127.0.0.1', '[::1]', '::1'],
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->allowedHosts = array_values(array_map('strtolower', $allowedHosts));
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ('' !== $origin) {
            if (!$this->isAllowedOrigin($origin)) {
                return $this->createForbiddenResponse('Forbidden: Invalid Origin header.');
            }

            return $handler->handle($request);
        }

        $host = $request->getHeaderLine('Host');
        if ('' !== $host && !$this->isAllowedHost($host)) {
            return $this->createForbiddenResponse('Forbidden: Invalid Host header.');
        }

        return $handler->handle($request);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $parsed = parse_url($origin);
        if (false === $parsed || !isset($parsed['host'])) {
            return false;
        }

        return \in_array(strtolower($parsed['host']), $this->allowedHosts, true);
    }

    /**
     * Validates the Host header value (host or host:port) against the allowed list.
     */
    private function isAllowedHost(string $host): bool
    {
        // IPv6 host with port: [::1]:8080
        if (str_starts_with($host, '[')) {
            $closingBracket = strpos($host, ']');
            if (false === $closingBracket) {
                return false;
            }
            $hostname = substr($host, 0, $closingBracket + 1);
        } else {
            // Strip port if present (host:port)
            $hostname = explode(':', $host, 2)[0];
        }

        return \in_array(strtolower($hostname), $this->allowedHosts, true);
    }

    private function createForbiddenResponse(string $message): ResponseInterface
    {
        $body = json_encode(Error::forInvalidRequest($message), \JSON_THROW_ON_ERROR);

        return $this->responseFactory
            ->createResponse(403)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
