<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces MCP HTTP authorization requirements and serves protected resource metadata.
 *
 * This middleware:
 * - Serves Protected Resource Metadata (RFC 9728) at configured well-known paths
 * - Validates Bearer tokens via the configured validator
 * - Returns 401 with WWW-Authenticate header on missing/invalid tokens
 * - Returns 403 on insufficient scope
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    private array $metadataPaths;

    /** @var callable(ServerRequestInterface): list<string>|null */
    private $scopeProvider;

    /**
     * @param ProtectedResourceMetadata                           $metadata            The protected resource metadata to serve
     * @param AuthorizationTokenValidatorInterface                $validator           Token validator implementation
     * @param ResponseFactoryInterface|null                       $responseFactory     PSR-17 response factory (auto-discovered if null)
     * @param StreamFactoryInterface|null                         $streamFactory       PSR-17 stream factory (auto-discovered if null)
     * @param list<string>                                        $metadataPaths       Paths where metadata should be served (e.g., ["/.well-known/oauth-protected-resource"])
     * @param string|null                                         $resourceMetadataUrl Explicit URL for the resource_metadata in WWW-Authenticate
     * @param callable(ServerRequestInterface): list<string>|null $scopeProvider       Optional callback to determine required scopes per request
     */
    public function __construct(
        private ProtectedResourceMetadata $metadata,
        private AuthorizationTokenValidatorInterface $validator,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $metadataPaths = [],
        private ?string $resourceMetadataUrl = null,
        ?callable $scopeProvider = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->metadataPaths = $this->normalizePaths($metadataPaths);
        $this->scopeProvider = $scopeProvider;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isMetadataRequest($request)) {
            return $this->createMetadataResponse();
        }

        $authorization = $request->getHeaderLine('Authorization');
        if ('' === $authorization) {
            return $this->buildErrorResponse($request, AuthorizationResult::unauthorized());
        }

        $accessToken = $this->parseBearerToken($authorization);
        if (null === $accessToken) {
            return $this->buildErrorResponse(
                $request,
                AuthorizationResult::badRequest('invalid_request', 'Malformed Authorization header.'),
            );
        }

        $result = $this->validator->validate($request, $accessToken);
        if (!$result->isAllowed()) {
            return $this->buildErrorResponse($request, $result);
        }

        return $handler->handle($this->applyAttributes($request, $result->getAttributes()));
    }

    private function createMetadataResponse(): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($this->metadata->toJson()));
    }

    private function isMetadataRequest(ServerRequestInterface $request): bool
    {
        if ([] === $this->metadataPaths || 'GET' !== $request->getMethod()) {
            return false;
        }

        return \in_array($request->getUri()->getPath(), $this->metadataPaths, true);
    }

    private function buildErrorResponse(ServerRequestInterface $request, AuthorizationResult $result): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($result->getStatusCode());
        $header = $this->buildAuthenticateHeader($request, $result);

        if (null !== $header) {
            $response = $response->withHeader('WWW-Authenticate', $header);
        }

        return $response;
    }

    private function buildAuthenticateHeader(ServerRequestInterface $request, AuthorizationResult $result): ?string
    {
        $parts = [];

        $resourceMetadataUrl = $this->resolveResourceMetadataUrl($request);
        if (null !== $resourceMetadataUrl) {
            $parts[] = 'resource_metadata="'.$this->escapeHeaderValue($resourceMetadataUrl).'"';
        }

        $scopes = $this->resolveScopes($request, $result);
        if (null !== $scopes) {
            $parts[] = 'scope="'.$this->escapeHeaderValue(implode(' ', $scopes)).'"';
        }

        if (null !== $result->getError()) {
            $parts[] = 'error="'.$this->escapeHeaderValue($result->getError()).'"';
        }

        if (null !== $result->getErrorDescription()) {
            $parts[] = 'error_description="'.$this->escapeHeaderValue($result->getErrorDescription()).'"';
        }

        if ([] === $parts) {
            return 'Bearer';
        }

        return 'Bearer '.implode(', ', $parts);
    }

    /**
     * @return list<string>|null
     */
    private function resolveScopes(ServerRequestInterface $request, AuthorizationResult $result): ?array
    {
        $scopes = $this->normalizeScopes($result->getScopes());
        if (null !== $scopes) {
            return $scopes;
        }

        if (null !== $this->scopeProvider) {
            $provided = ($this->scopeProvider)($request);
            $scopes = $this->normalizeScopes($provided);
            if (null !== $scopes) {
                return $scopes;
            }
        }

        return $this->normalizeScopes($this->metadata->getScopesSupported());
    }

    /**
     * @param list<string>|null $scopes
     *
     * @return list<string>|null
     */
    private function normalizeScopes(?array $scopes): ?array
    {
        if (null === $scopes) {
            return null;
        }

        $normalized = array_values(array_filter(array_map('trim', $scopes), static function (string $scope): bool {
            return '' !== $scope;
        }));

        return [] === $normalized ? null : $normalized;
    }

    private function resolveResourceMetadataUrl(ServerRequestInterface $request): ?string
    {
        if (null !== $this->resourceMetadataUrl) {
            return $this->resourceMetadataUrl;
        }

        if ([] === $this->metadataPaths) {
            return null;
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $authority = $uri->getAuthority();

        if ('' === $scheme || '' === $authority) {
            return null;
        }

        return $scheme.'://'.$authority.$this->metadataPaths[0];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function applyAttributes(ServerRequestInterface $request, array $attributes): ServerRequestInterface
    {
        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $path) {
            $path = trim($path);
            if ('' === $path) {
                continue;
            }
            if ('/' !== $path[0]) {
                $path = '/'.$path;
            }
            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    private function parseBearerToken(string $authorization): ?string
    {
        if (!preg_match('/^Bearer\\s+(.+)$/i', $authorization, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return '' === $token ? null : $token;
    }

    private function escapeHeaderValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
