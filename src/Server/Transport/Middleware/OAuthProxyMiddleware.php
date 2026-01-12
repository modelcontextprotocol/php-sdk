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
use Http\Discovery\Psr18ClientDiscovery;
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
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    private ?array $upstreamMetadata = null;

    /**
     * @param string $upstreamIssuer The issuer URL of the upstream OAuth provider
     * @param string $localBaseUrl The base URL of this MCP server (e.g., http://localhost:8000)
     * @param string|null $clientSecret Optional client secret for confidential clients
     */
    public function __construct(
        private readonly string $upstreamIssuer,
        private readonly string $localBaseUrl,
        private readonly ?string $clientSecret = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Serve local authorization server metadata
        if ('GET' === $request->getMethod() && '/.well-known/oauth-authorization-server' === $path) {
            return $this->createAuthServerMetadataResponse();
        }

        // Handle authorization endpoint - redirect to upstream
        if ('GET' === $request->getMethod() && '/authorize' === $path) {
            return $this->handleAuthorize($request);
        }

        // Handle token endpoint - proxy to upstream
        if ('POST' === $request->getMethod() && '/token' === $path) {
            return $this->handleToken($request);
        }

        // Pass through to next handler
        return $handler->handle($request);
    }

    private function handleAuthorize(ServerRequestInterface $request): ResponseInterface
    {
        $upstreamMetadata = $this->getUpstreamMetadata();
        $authorizationEndpoint = $upstreamMetadata['authorization_endpoint'] ?? null;

        if (null === $authorizationEndpoint) {
            return $this->createErrorResponse(500, 'Upstream authorization endpoint not found');
        }

        // Get the raw query string to preserve exact encoding (important for PKCE)
        $rawQueryString = $request->getUri()->getQuery();

        // Build upstream URL preserving exact query string
        $upstreamUrl = $authorizationEndpoint . '?' . $rawQueryString;

        // Redirect to upstream authorization server
        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $upstreamUrl)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function handleToken(ServerRequestInterface $request): ResponseInterface
    {
        $upstreamMetadata = $this->getUpstreamMetadata();
        $tokenEndpoint = $upstreamMetadata['token_endpoint'] ?? null;

        if (null === $tokenEndpoint) {
            return $this->createErrorResponse(500, 'Upstream token endpoint not found');
        }

        // Get the request body and parse it
        $body = (string)$request->getBody();
        parse_str($body, $params);

        // Inject client_secret if configured and not already present
        if (null !== $this->clientSecret && !isset($params['client_secret'])) {
            $params['client_secret'] = $this->clientSecret;
        }

        // Rebuild body with potentially added client_secret
        $body = http_build_query($params);

        // Create upstream request
        $upstreamRequest = $this->requestFactory
            ->createRequest('POST', $tokenEndpoint)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));

        // Forward any Authorization header (for client credentials)
        if ($request->hasHeader('Authorization')) {
            $upstreamRequest = $upstreamRequest->withHeader('Authorization', $request->getHeaderLine('Authorization'));
        }

        try {
            $upstreamResponse = $this->httpClient->sendRequest($upstreamRequest);
            $responseBody = (string)$upstreamResponse->getBody();

            // Return upstream response as-is
            return $this->responseFactory
                ->createResponse($upstreamResponse->getStatusCode())
                ->withHeader('Content-Type', $upstreamResponse->getHeaderLine('Content-Type'))
                ->withHeader('Cache-Control', 'no-store')
                ->withBody($this->streamFactory->createStream($responseBody));
        } catch (\Throwable $e) {
            return $this->createErrorResponse(502, 'Failed to contact upstream token endpoint: ' . $e->getMessage());
        }
    }

    private function createAuthServerMetadataResponse(): ResponseInterface
    {
        $upstreamMetadata = $this->getUpstreamMetadata();

        // Create local metadata that points to our proxy endpoints
        $localMetadata = [
            'issuer' => $this->upstreamIssuer,
            'authorization_endpoint' => rtrim($this->localBaseUrl, '/') . '/authorize',
            'token_endpoint' => rtrim($this->localBaseUrl, '/') . '/token',
            'response_types_supported' => $upstreamMetadata['response_types_supported'] ?? ['code'],
            'grant_types_supported' => $upstreamMetadata['grant_types_supported'] ?? ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => $upstreamMetadata['code_challenge_methods_supported'] ?? ['S256'],
        ];

        // Copy additional useful fields from upstream
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

    private function getUpstreamMetadata(): array
    {
        if (null !== $this->upstreamMetadata) {
            return $this->upstreamMetadata;
        }

        // Try OpenID Connect discovery first
        $discoveryUrls = [
            rtrim($this->upstreamIssuer, '/') . '/.well-known/openid-configuration',
            rtrim($this->upstreamIssuer, '/') . '/.well-known/oauth-authorization-server',
        ];

        foreach ($discoveryUrls as $url) {
            try {
                $request = $this->requestFactory->createRequest('GET', $url);
                $response = $this->httpClient->sendRequest($request);

                if (200 === $response->getStatusCode()) {
                    $this->upstreamMetadata = json_decode((string)$response->getBody(), true) ?? [];

                    return $this->upstreamMetadata;
                }
            } catch (\Throwable) {
                // Try next URL
            }
        }

        $this->upstreamMetadata = [];

        return $this->upstreamMetadata;
    }

    private function createErrorResponse(int $status, string $message): ResponseInterface
    {
        $body = json_encode(['error' => 'server_error', 'error_description' => $message]);

        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streamFactory->createStream($body));
    }
}
