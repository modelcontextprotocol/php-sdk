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
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\Http\OAuth\ScopePolicy;
use Mcp\Server\Transport\ReadsBoundedBody;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Enforces MCP HTTP authorization: the MCP server as OAuth 2.1 resource server.
 *
 * This middleware:
 * - Validates Bearer tokens via the configured validator
 * - Returns 401 with WWW-Authenticate header on missing/invalid tokens
 * - Returns 403 insufficient_scope when the token lacks a scope the {@see ScopePolicy} requires
 * - Hands the validated {@see AccessToken} to the transport as request attribute,
 *   from where handlers read it via {@see \Mcp\Server\RequestContext::getAccessToken()}
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class AuthorizationMiddleware implements MiddlewareInterface
{
    use ReadsBoundedBody;

    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param AuthorizationTokenValidatorInterface $validator        Token validator implementation
     * @param ProtectedResourceMetadata            $resourceMetadata Metadata of this resource, its URL is advertised in challenges
     * @param ScopePolicy|null                     $scopePolicy      Scopes required per request, none if null
     * @param int                                  $maxBodyBytes     Upper bound for reading the body when the scope policy inspects it
     */
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $validator,
        private readonly ProtectedResourceMetadata $resourceMetadata,
        private readonly ?ScopePolicy $scopePolicy = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly int $maxBodyBytes = StreamableHttpTransport::DEFAULT_MAX_BODY_BYTES,
    ) {
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // No credentials, or another scheme: a plain challenge without error code (RFC 6750 §3.1).
        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer(?: |$)/i', $authorization)) {
            return $this->buildErrorResponse(AuthorizationResult::unauthorized());
        }

        $accessToken = $this->parseBearerToken($authorization);
        if (null === $accessToken) {
            return $this->buildErrorResponse(AuthorizationResult::badRequest('invalid_request', 'Malformed Authorization header.'));
        }

        $result = $this->validator->validate($accessToken);
        $token = $result->getAccessToken();
        if (!$result->isAllowed() || null === $token) {
            return $this->buildErrorResponse($result);
        }

        if (null !== $this->scopePolicy) {
            $payload = null;
            if ('POST' === $request->getMethod() && $this->scopePolicy->inspectsBody()) {
                $body = $this->readBoundedBody($request->getBody(), $this->maxBodyBytes);
                if (null === $body) {
                    return $this->buildErrorResponse(AuthorizationResult::badRequest('invalid_request', 'Request body is too large.'));
                }

                // Handed on byte for byte: the transport reads the body again.
                $request = $request->withBody($this->streamFactory->createStream($body));
                $payload = json_decode($body, true);
            }

            $required = $this->scopePolicy->requiredFor($payload);
            $granted = $this->scopePolicy->expand($token->getScopes());
            if ([] !== array_diff($required, $granted)) {
                return $this->buildErrorResponse(AuthorizationResult::forbidden('insufficient_scope', 'The access token lacks a required scope.', $required));
            }

            // Handlers checking AccessToken::hasScope() see the hierarchy the policy enforces.
            $token = new AccessToken($granted, $token->getClaims());
        }

        return $handler->handle($request->withAttribute(AccessToken::class, $token));
    }

    private function buildErrorResponse(AuthorizationResult $result): ResponseInterface
    {
        $parts = ['resource_metadata="'.$this->escapeHeaderValue($this->resourceMetadata->getMetadataUrl()).'"'];

        $scopes = $result->getScopes() ?? $this->resourceMetadata->getScopesSupported();
        if (null !== $scopes && [] !== $scopes) {
            $parts[] = 'scope="'.$this->escapeHeaderValue(implode(' ', $scopes)).'"';
        }

        if (null !== $result->getError()) {
            $parts[] = 'error="'.$this->escapeHeaderValue($result->getError()).'"';
        }

        if (null !== $result->getErrorDescription()) {
            $parts[] = 'error_description="'.$this->escapeHeaderValue($result->getErrorDescription()).'"';
        }

        return $this->responseFactory
            ->createResponse($result->getStatusCode())
            ->withHeader('WWW-Authenticate', 'Bearer '.implode(', ', $parts));
    }

    /**
     * The b64token of an `Authorization: Bearer` header (RFC 6750 §2.1), null if it has none or a malformed one.
     */
    private function parseBearerToken(string $authorization): ?string
    {
        if (!preg_match('/^Bearer +([A-Za-z0-9\-._~+\/]+=*) *$/i', $authorization, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Quoted-string per RFC 9110 §5.6.4. Error descriptions come from validators, so control
     * characters - CR and LF above all - are stripped to keep them from injecting headers.
     */
    private function escapeHeaderValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');
    }
}
