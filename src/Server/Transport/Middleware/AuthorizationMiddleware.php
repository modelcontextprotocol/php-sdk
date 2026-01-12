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
     * @param ProtectedResourceMetadata $metadata The protected resource metadata to serve
     * @param AuthorizationTokenValidatorInterface $validator Token validator implementation
     * @param ResponseFactoryInterface|null $responseFactory PSR-17 response factory (auto-discovered if null)
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory (auto-discovered if null)
     * @param list<string> $metadataPaths Paths where metadata should be served (e.g., ["/.well-known/oauth-protected-resource"])
     * @param string|null $resourceMetadataUrl Explicit URL for the resource_metadata in WWW-Authenticate
     * @param callable(ServerRequestInterface): list<string>|null $scopeProvider Optional callback to determine required scopes per request
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
        // Serve metadata at well-known paths
        if ($this->isMetadataRequest($request)) {
            return $this->createMetadataResponse();
        }

        // Extract Authorization header
        $authorization = $request->getHeaderLine('Authorization');
        if ('' === $authorization) {
            return $this->buildErrorResponse($request, AuthorizationResult::unauthorized());
        }

        // Parse Bearer token
        $accessToken = $this->parseBearerToken($authorization);
        if (null === $accessToken) {
            return $this->buildErrorResponse(
                $request,
                AuthorizationResult::badRequest('invalid_request', 'Malformed Authorization header.'),
            );
        }

        // Validate the token
        $result = $this->validator->validate($request, $accessToken);
        if ($result->isAllowed()) {
            return $handler->handle($this->applyAttributes($request, $result->getAttributes()));
        }

        return $this->buildErrorResponse($request, $result);
    }

    private function createMetadataResponse(): ResponseInterface
    {
        $payload = $this->metadata->toJson();

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($payload));
    }

    private function isMetadataRequest(ServerRequestInterface $request): bool
    {
        if (empty($this->metadataPaths)) {
            return false;
        }

        if ('GET' !== $request->getMethod()) {
            return false;
        }

        $path = $request->getUri()->getPath();

        foreach ($this->metadataPaths as $metadataPath) {
            if ($path === $metadataPath) {
                return true;
            }
        }

        return false;
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

        // Include resource_metadata URL per RFC 9728
        $resourceMetadataUrl = $this->resolveResourceMetadataUrl($request);
        if (null !== $resourceMetadataUrl) {
            $parts[] = 'resource_metadata="' . $this->escapeHeaderValue($resourceMetadataUrl) . '"';
        }

        // Include scope hint per RFC 6750 Section 3
        $scopes = $this->resolveScopes($request, $result);
        if (!empty($scopes)) {
            $parts[] = 'scope="' . $this->escapeHeaderValue(implode(' ', $scopes)) . '"';
        }

        // Include error details
        if (null !== $result->getError()) {
            $parts[] = 'error="' . $this->escapeHeaderValue($result->getError()) . '"';
        }

        if (null !== $result->getErrorDescription()) {
            $parts[] = 'error_description="' . $this->escapeHeaderValue($result->getErrorDescription()) . '"';
        }

        if (empty($parts)) {
            return 'Bearer';
        }

        return 'Bearer ' . implode(', ', $parts);
    }

    /**
     * @return list<string>|null
     */
    private function resolveScopes(ServerRequestInterface $request, AuthorizationResult $result): ?array
    {
        // First, check if the result has specific scopes (e.g., from insufficient_scope error)
        $scopes = $this->normalizeScopes($result->getScopes());
        if (null !== $scopes) {
            return $scopes;
        }

        // Then, check the scope provider callback
        if (null !== $this->scopeProvider) {
            $provided = ($this->scopeProvider)($request);
            $scopes = $this->normalizeScopes($provided);
            if (null !== $scopes) {
                return $scopes;
            }
        }

        // Fall back to scopes from metadata
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

        return empty($normalized) ? null : $normalized;
    }

    private function resolveResourceMetadataUrl(ServerRequestInterface $request): ?string
    {
        // Use explicit URL if configured
        if (null !== $this->resourceMetadataUrl) {
            return $this->resourceMetadataUrl;
        }

        // Auto-generate from request if metadata paths are configured
        if (empty($this->metadataPaths)) {
            return null;
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $host = $uri->getHost();

        if ('' === $scheme || '' === $host) {
            return null;
        }

        $authority = $host;
        $port = $uri->getPort();

        if (null !== $port && !$this->isDefaultPort($scheme, $port)) {
            $authority .= ':' . $port;
        }

        return $scheme . '://' . $authority . $this->metadataPaths[0];
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ('https' === $scheme && 443 === $port) || ('http' === $scheme && 80 === $port);
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
                $path = '/' . $path;
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
