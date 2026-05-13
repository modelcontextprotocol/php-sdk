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
use Mcp\Server\Transport\Http\JsonRpcErrorResponse;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects local MCP servers against DNS rebinding attacks.
 *
 * When the request carries an `Origin` header it is validated against the
 * allowlist; otherwise the `Host` header is validated. Both checks are
 * case-insensitive and ignore port. Default allowlist contains localhost
 * variants only — for non-local deployments either pass a tailored list of
 * hostnames or omit this middleware entirely (e.g. when fronted by a reverse
 * proxy that enforces Host validation).
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#security-warning
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class DnsRebindingProtectionMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    private readonly array $allowedHosts;

    /**
     * @param list<string>                  $allowedHosts    Hostnames (without port) that are permitted. Defaults to localhost variants.
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 response factory (auto-discovered if null)
     * @param StreamFactoryInterface|null   $streamFactory   PSR-17 stream factory (auto-discovered if null)
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

    private function isAllowedHost(string $host): bool
    {
        if (str_starts_with($host, '[')) {
            $closingBracket = strpos($host, ']');
            if (false === $closingBracket) {
                return false;
            }
            $hostname = substr($host, 0, $closingBracket + 1);
        } else {
            $hostname = explode(':', $host, 2)[0];
        }

        return \in_array(strtolower($hostname), $this->allowedHosts, true);
    }

    private function createForbiddenResponse(string $message): ResponseInterface
    {
        return JsonRpcErrorResponse::create($this->responseFactory, $this->streamFactory, 403, $message);
    }
}
