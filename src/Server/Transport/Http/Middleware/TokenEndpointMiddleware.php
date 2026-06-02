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
use Mcp\Exception\OAuthException;
use Mcp\Server\Transport\Http\OAuth\TokenGranterInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OAuth 2.0 token endpoint (RFC 6749 Section 5).
 *
 * Thin PSR-7 shell: parses the form body (and any HTTP Basic client
 * credentials), delegates to a {@see TokenGranterInterface}, and serializes the
 * RFC 6749 §5.1 success or §5.2 error response. All responses are non-cacheable.
 */
final class TokenEndpointMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly TokenGranterInterface $granter,
        private readonly string $path = '/token',
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('POST' !== $request->getMethod() || $this->path !== $request->getUri()->getPath()) {
            return $handler->handle($request);
        }

        parse_str($request->getBody()->__toString(), $params);
        $params = $this->applyBasicAuth($request, $params);

        $grantType = $params['grant_type'] ?? '';
        if (!\is_string($grantType)) {
            $grantType = '';
        }

        try {
            $tokenResponse = $this->granter->grant($grantType, $params);
        } catch (OAuthException $e) {
            return $this->json($e->httpStatus, $e->toArray());
        }

        return $this->json(200, $tokenResponse->toArray());
    }

    /**
     * Normalizes HTTP Basic client credentials into the params array
     * (client_secret_basic), without overriding body-provided values.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function applyBasicAuth(ServerRequestInterface $request, array $params): array
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
            return $params;
        }

        $decoded = base64_decode(trim($matches[1]), true);
        if (false === $decoded || !str_contains($decoded, ':')) {
            return $params;
        }

        [$clientId, $clientSecret] = explode(':', $decoded, 2);
        $params['client_id'] ??= rawurldecode($clientId);
        $params['client_secret'] ??= rawurldecode($clientSecret);

        return $params;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withBody($this->streamFactory->createStream(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
    }
}
