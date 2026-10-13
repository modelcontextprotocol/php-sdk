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
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects local MCP servers against DNS rebinding attacks.
 *
 * The Host header is always validated against allowedHosts, ignoring case and
 * port. If present, Origin must match the request URI origin or an exact entry
 * in allowedOrigins, including scheme and port, unless allowedOrigins contains '*'.
 * By default, only localhost hosts
 * with same-origin requests or requests without an Origin header are allowed.
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
     *                                                       IPv6 addresses must be bracketed (e.g. `[::1]`). Use `['*']` to allow any non-empty Host header.
     * @param list<string>                  $allowedOrigins  Exact origins (scheme, host and optional port) that are permitted. Same-origin requests are always permitted. Use `['*']` to allow any Origin header.
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 response factory (auto-discovered if null)
     * @param StreamFactoryInterface|null   $streamFactory   PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        array $allowedHosts = ['localhost', '127.0.0.1', '[::1]'],
        private readonly array $allowedOrigins = [],
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->allowedHosts = array_values(array_map('strtolower', $allowedHosts));
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isAllowedHost($request->getHeaderLine('Host'))) {
            return $this->createForbiddenResponse('Forbidden: Invalid Host header.');
        }

        if ($request->hasHeader('Origin')
            && !\in_array('*', $this->allowedOrigins, true)
            && !$this->isSameOrigin($request)
            && !\in_array($request->getHeaderLine('Origin'), $this->allowedOrigins, true)
        ) {
            return $this->createForbiddenResponse('Forbidden: Invalid Origin header.');
        }

        return $handler->handle($request);
    }

    private function isSameOrigin(ServerRequestInterface $request): bool
    {
        $uri = $request->getUri()->withUserInfo('')->withPath('')->withQuery('')->withFragment('');
        if ('' === $uri->getScheme() || '' === $uri->getHost()) {
            return false;
        }

        $origin = strtolower($request->getHeaderLine('Origin'));
        if ($origin === (string) $uri) {
            return true;
        }

        // PSR-7 omits default ports, but an Origin may include one explicitly.
        return null === $uri->getPort()
            && $origin === $uri.('https' === $uri->getScheme() ? ':443' : ':80');
    }

    private function isAllowedHost(string $host): bool
    {
        if (\in_array('*', $this->allowedHosts, true)) {
            return '' !== $host;
        }

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
        return $this->responseFactory
            ->createResponse(403)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->streamFactory->createStream($message));
    }
}
