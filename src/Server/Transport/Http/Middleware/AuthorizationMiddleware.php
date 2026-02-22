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
use Mcp\Exception\RuntimeException;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces MCP HTTP authorization requirements.
 *
 * This middleware:
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

    /**
     * @param AuthorizationTokenValidatorInterface $validator        Token validator implementation
     * @param ProtectedResourceMetadata            $resourceMetadata Protected resource metadata object used for challenge hints
     * @param ResponseFactoryInterface|null        $responseFactory  PSR-17 response factory (auto-discovered if null)
     */
    public function __construct(
        private AuthorizationTokenValidatorInterface $validator,
        private ProtectedResourceMetadata $resourceMetadata,
        ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
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

        $result = $this->validator->validate($accessToken);
        if (!$result->isAllowed()) {
            return $this->buildErrorResponse($request, $result);
        }

        return $handler->handle($this->applyAttributes($request, $result->getAttributes()));
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

        $parts[] = 'resource_metadata="'.$this->escapeHeaderValue($this->resolveResourceMetadataUrl($request)).'"';

        $scopes = $this->resolveScopes($result);
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
    private function resolveScopes(AuthorizationResult $result): ?array
    {
        $scopes = $this->normalizeScopes($result->getScopes());
        if (null !== $scopes) {
            return $scopes;
        }

        return $this->normalizeScopes($this->resourceMetadata->getScopesSupported());
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

    private function resolveResourceMetadataUrl(ServerRequestInterface $request): string
    {
        $metadataPath = $this->resourceMetadata->getPrimaryMetadataPath();

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $authority = $uri->getAuthority();

        if ('' === $scheme || '' === $authority) {
            throw new RuntimeException('Cannot resolve resource metadata URL: request URI must have scheme and authority');
        }

        return $scheme.'://'.$authority.$metadataPath;
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

    private function parseBearerToken(string $authorization): ?string
    {
        if (!preg_match('/^Bearer\\s+(.+)$/', $authorization, $matches)) {
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
