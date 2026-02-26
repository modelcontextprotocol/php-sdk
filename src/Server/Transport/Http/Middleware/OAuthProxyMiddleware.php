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
use Http\Discovery\Psr18ClientDiscovery;
use Mcp\Server\Transport\Http\OAuth\OidcDiscoveryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Proxies OAuth authorization requests to an upstream authorization server.
 *
 * This middleware implements the OAuth proxy pattern for MCP servers that
 * delegate authorization to third-party OAuth providers (Microsoft, Keycloak, etc.).
 *
 * It handles:
 * - /authorize: Redirects to the upstream authorization server
 * - /token: Proxies token requests to the upstream token endpoint
 * - /.well-known/oauth-authorization-server: Serves authorization server metadata
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class OAuthProxyMiddleware implements MiddlewareInterface
{
    private const CLIENT_SECRET_BASIC = 'client_secret_basic';
    private const CLIENT_SECRET_POST = 'client_secret_post';

    private ?ClientInterface $httpClient;
    private ?RequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param string                 $upstreamIssuer The issuer URL of the upstream OAuth provider
     * @param string                 $localBaseUrl   The base URL of this MCP server (e.g., http://localhost:8000)
     * @param string|null            $clientSecret   Optional client secret for confidential clients
     * @param OidcDiscoveryInterface $discovery      OIDC discovery provider for upstream metadata
     */
    public function __construct(
        private readonly string $upstreamIssuer,
        private readonly string $localBaseUrl,
        private readonly OidcDiscoveryInterface $discovery,
        private readonly ?string $clientSecret = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('GET' === $request->getMethod() && '/.well-known/oauth-authorization-server' === $path) {
            return $this->createAuthServerMetadataResponse();
        }

        if ('GET' === $request->getMethod() && '/authorize' === $path) {
            return $this->handleAuthorize($request);
        }

        if ('POST' === $request->getMethod() && '/token' === $path) {
            return $this->handleToken($request);
        }

        return $handler->handle($request);
    }

    private function handleAuthorize(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $authorizationEndpoint = $this->discovery->getAuthorizationEndpoint($this->upstreamIssuer);
        } catch (\Throwable) {
            return $this->createErrorResponse(500, 'Upstream authorization endpoint not found');
        }

        $rawQueryString = $request->getUri()->getQuery();
        $upstreamUrl = $authorizationEndpoint;
        if ('' !== $rawQueryString) {
            $upstreamUrl .= '?'.$rawQueryString;
        }

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $upstreamUrl)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function handleToken(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $tokenEndpoint = $this->discovery->getTokenEndpoint($this->upstreamIssuer);
        } catch (\Throwable) {
            return $this->createErrorResponse(500, 'Upstream token endpoint not found');
        }

        $body = $request->getBody()->__toString();
        parse_str($body, $params);

        $upstreamAuthorization = trim($request->getHeaderLine('Authorization'));
        if ('' === $upstreamAuthorization) {
            $upstreamAuthorization = null;
        }

        if (null !== $this->clientSecret && !isset($params['client_secret']) && null === $upstreamAuthorization) {
            $authMethod = $this->resolveTokenEndpointAuthMethod();

            if (self::CLIENT_SECRET_BASIC === $authMethod) {
                $clientId = $params['client_id'] ?? null;

                if (\is_string($clientId) && '' !== trim($clientId)) {
                    $upstreamAuthorization = 'Basic '.base64_encode(trim($clientId).':'.$this->clientSecret);
                } else {
                    $params['client_secret'] = $this->clientSecret;
                }
            } else {
                $params['client_secret'] = $this->clientSecret;
            }
        }

        $body = http_build_query($params);

        $upstreamRequest = $this->getRequestFactory()
            ->createRequest('POST', $tokenEndpoint)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));

        if (null !== $upstreamAuthorization) {
            $upstreamRequest = $upstreamRequest->withHeader('Authorization', $upstreamAuthorization);
        }

        try {
            $upstreamResponse = $this->getHttpClient()->sendRequest($upstreamRequest);
            $responseBody = $upstreamResponse->getBody()->__toString();

            return $this->responseFactory
                ->createResponse($upstreamResponse->getStatusCode())
                ->withHeader('Content-Type', $upstreamResponse->getHeaderLine('Content-Type'))
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($this->streamFactory->createStream($responseBody));
        } catch (\Throwable $e) {
            return $this->createErrorResponse(502, 'Failed to contact upstream token endpoint: '.$e->getMessage());
        }
    }

    private function createAuthServerMetadataResponse(): ResponseInterface
    {
        try {
            $upstreamMetadata = $this->discovery->discover($this->upstreamIssuer);
        } catch (\Throwable) {
            return $this->createErrorResponse(500, 'Failed to discover upstream server metadata');
        }

        $localBaseUrl = rtrim($this->localBaseUrl, '/');
        $localMetadata = [
            'issuer' => $localBaseUrl,
            'authorization_endpoint' => $localBaseUrl.'/authorize',
            'token_endpoint' => $localBaseUrl.'/token',
            'response_types_supported' => $upstreamMetadata['response_types_supported'] ?? ['code'],
            'grant_types_supported' => $upstreamMetadata['grant_types_supported'] ?? ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => $upstreamMetadata['code_challenge_methods_supported'] ?? ['S256'],
        ];

        $copyFields = [
            'scopes_supported',
            'token_endpoint_auth_methods_supported',
            'jwks_uri',
        ];

        foreach ($copyFields as $field) {
            if (isset($upstreamMetadata[$field])) {
                $localMetadata[$field] = $upstreamMetadata[$field];
            }
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'max-age=3600')
            ->withBody($this->streamFactory->createStream(json_encode($localMetadata, \JSON_UNESCAPED_SLASHES)));
    }

    private function createErrorResponse(int $status, string $message): ResponseInterface
    {
        $body = json_encode(['error' => 'server_error', 'error_description' => $message]);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }

    private function resolveTokenEndpointAuthMethod(): string
    {
        $supportedMethods = $this->getTokenEndpointAuthMethods();

        if (\in_array(self::CLIENT_SECRET_BASIC, $supportedMethods, true)) {
            return self::CLIENT_SECRET_BASIC;
        }

        if (\in_array(self::CLIENT_SECRET_POST, $supportedMethods, true)) {
            return self::CLIENT_SECRET_POST;
        }

        return self::CLIENT_SECRET_POST;
    }

    /**
     * @return list<string>
     */
    private function getTokenEndpointAuthMethods(): array
    {
        try {
            $metadata = $this->discovery->discover($this->upstreamIssuer);
        } catch (\Throwable) {
            return [];
        }

        $methods = $metadata['token_endpoint_auth_methods_supported'] ?? null;
        if (!\is_array($methods)) {
            return [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (!\is_string($method)) {
                continue;
            }

            $method = trim($method);
            if ('' === $method) {
                continue;
            }

            $normalized[] = $method;
        }

        return array_values(array_unique($normalized));
    }

    private function getHttpClient(): ClientInterface
    {
        return $this->httpClient ??= Psr18ClientDiscovery::find();
    }

    private function getRequestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory ??= Psr17FactoryDiscovery::findRequestFactory();
    }
}
