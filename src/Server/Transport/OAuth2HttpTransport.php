<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Server\Auth\AuthenticationResult;
use Mcp\Server\Auth\OAuth2Configuration;
use Mcp\Server\Auth\WwwAuthenticateChallenge;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP Transport with OAuth 2.0 authentication support.
 *
 * Implements:
 * - Bearer token validation
 * - Protected Resource Metadata endpoint (RFC 9728)
 * - WWW-Authenticate challenges (RFC 6750)
 *
 * @extends StreamableHttpTransport
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class OAuth2HttpTransport extends StreamableHttpTransport
{
    private ?AuthenticationResult $authResult = null;

    /**
     * @param array<string, string> $corsHeaders
     */
    public function __construct(
        private readonly OAuth2Configuration $authConfig,
        ServerRequestInterface $request,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        array $corsHeaders = [],
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($request, $responseFactory, $streamFactory, $corsHeaders, $logger);
    }

    public function listen(): ResponseInterface
    {
        $path = $this->request->getUri()->getPath();
        $method = $this->request->getMethod();

        // Handle Protected Resource Metadata endpoint
        if ($this->isProtectedResourceMetadataRequest($path)) {
            return $this->handleProtectedResourceMetadata();
        }

        // Skip authentication for OPTIONS (CORS preflight)
        if ('OPTIONS' === $method) {
            return parent::listen();
        }

        // Skip authentication for public paths
        if ($this->authConfig->isPublicPath($path)) {
            return parent::listen();
        }

        // Authenticate the request
        $authResult = $this->authenticateRequest();
        if (!$authResult->authenticated) {
            return $this->createUnauthorizedResponse($authResult);
        }

        $this->authResult = $authResult;

        // Continue with normal request handling
        return parent::listen();
    }

    /**
     * Get the authentication result for the current request.
     */
    public function getAuthenticationResult(): ?AuthenticationResult
    {
        return $this->authResult;
    }

    /**
     * Check if this is a request for Protected Resource Metadata.
     */
    private function isProtectedResourceMetadataRequest(string $path): bool
    {
        // Check exact match
        if ($path === $this->authConfig->getResourceMetadataPath()) {
            return true;
        }

        // Check well-known path with resource path component
        if (str_starts_with($path, '/.well-known/oauth-protected-resource')) {
            return true;
        }

        return false;
    }

    /**
     * Handle Protected Resource Metadata request (RFC 9728).
     */
    private function handleProtectedResourceMetadata(): ResponseInterface
    {
        $metadata = $this->authConfig->resourceMetadata;

        try {
            $body = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to encode protected resource metadata', ['exception' => $e]);

            return $this->withCorsHeaders(
                $this->responseFactory->createResponse(500)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($this->streamFactory->createStream('{"error": "internal_error"}'))
            );
        }

        return $this->withCorsHeaders(
            $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Cache-Control', 'public, max-age=3600')
                ->withBody($this->streamFactory->createStream($body))
        );
    }

    /**
     * Authenticate the incoming request using Bearer token.
     */
    private function authenticateRequest(): AuthenticationResult
    {
        $authHeader = $this->request->getHeaderLine('Authorization');

        if ('' === $authHeader) {
            return AuthenticationResult::unauthenticated(
                'invalid_request',
                'Missing Authorization header'
            );
        }

        // Extract Bearer token
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return AuthenticationResult::unauthenticated(
                'invalid_request',
                'Invalid Authorization header format'
            );
        }

        $token = $matches[1];
        $expectedAudience = $this->authConfig->getExpectedAudience();

        return $this->authConfig->tokenAuthenticator->authenticate($token, $expectedAudience);
    }

    /**
     * Create a 401 Unauthorized response with WWW-Authenticate header.
     */
    private function createUnauthorizedResponse(AuthenticationResult $authResult): ResponseInterface
    {
        $metadataUrl = $this->authConfig->getResourceMetadataUrl();
        $scopesSupported = $this->authConfig->resourceMetadata->scopesSupported;

        $challenge = WwwAuthenticateChallenge::forUnauthorized(
            $metadataUrl,
            $scopesSupported ? implode(' ', $scopesSupported) : null,
            $authResult->errorDescription
        );

        $this->logger->info('Returning 401 Unauthorized', [
            'error' => $authResult->error,
            'error_description' => $authResult->errorDescription,
        ]);

        return $this->withCorsHeaders(
            $this->responseFactory->createResponse(401)
                ->withHeader('WWW-Authenticate', $challenge->build())
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode([
                    'error' => $authResult->error ?? 'unauthorized',
                    'error_description' => $authResult->errorDescription ?? 'Authorization required',
                ], JSON_THROW_ON_ERROR)))
        );
    }

    /**
     * Create a 403 Forbidden response for insufficient scope.
     *
     * @param string[] $requiredScopes
     */
    public function createForbiddenResponse(array $requiredScopes, ?string $description = null): ResponseInterface
    {
        $metadataUrl = $this->authConfig->getResourceMetadataUrl();

        $challenge = WwwAuthenticateChallenge::forInsufficientScope(
            $metadataUrl,
            $requiredScopes,
            $description
        );

        $this->logger->info('Returning 403 Forbidden', [
            'required_scopes' => $requiredScopes,
            'description' => $description,
        ]);

        return $this->withCorsHeaders(
            $this->responseFactory->createResponse(403)
                ->withHeader('WWW-Authenticate', $challenge->build())
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode([
                    'error' => 'insufficient_scope',
                    'error_description' => $description ?? 'Additional scope required',
                    'required_scopes' => $requiredScopes,
                ], JSON_THROW_ON_ERROR)))
        );
    }
}

