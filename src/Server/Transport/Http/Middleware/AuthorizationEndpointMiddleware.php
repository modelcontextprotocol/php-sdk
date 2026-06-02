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
use Mcp\Server\Transport\Http\OAuth\AuthorizationCodeIssuerInterface;
use Mcp\Server\Transport\Http\OAuth\ClientRepositoryInterface;
use Mcp\Server\Transport\Http\OAuth\ConsentInterface;
use Mcp\Server\Transport\Http\OAuth\Pkce;
use Mcp\Server\Transport\Http\OAuth\ResourceOwnerResolverInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OAuth 2.1 authorization endpoint (authorization code grant with mandatory
 * PKCE S256).
 *
 * Validates the request, resolves the resource owner (delegating login to the
 * host), runs consent, and issues an authorization code via the engine. Errors
 * about client_id / redirect_uri are rendered directly (never redirected) to
 * prevent open redirects; all other errors redirect back to the client.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-4.1
 */
final class AuthorizationEndpointMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    private array $supportedScopes;

    /**
     * @param list<string> $supportedScopes Allowed scopes (empty = accept any the client allows)
     */
    public function __construct(
        private readonly ClientRepositoryInterface $clients,
        private readonly AuthorizationCodeIssuerInterface $codeIssuer,
        private readonly ResourceOwnerResolverInterface $resourceOwner,
        private readonly ConsentInterface $consent,
        array $supportedScopes = [],
        private readonly string $path = '/authorize',
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->supportedScopes = array_values($supportedScopes);
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->path !== $request->getUri()->getPath() || !\in_array($request->getMethod(), ['GET', 'POST'], true)) {
            return $handler->handle($request);
        }

        $params = $this->collectParams($request);

        // 1. Client + redirect URI: errors here MUST NOT redirect (open-redirect prevention).
        $clientId = $this->stringParam($params, 'client_id');
        if (null === $clientId || null === ($client = $this->clients->find($clientId))) {
            return $this->directError(400, 'invalid_client', 'Unknown or missing client_id.');
        }

        $redirectUri = $this->stringParam($params, 'redirect_uri');
        if (null === $redirectUri || !$client->hasRedirectUri($redirectUri)) {
            return $this->directError(400, 'invalid_request', 'Missing or unregistered redirect_uri.');
        }

        $state = $this->stringParam($params, 'state');

        // 2. response_type
        if ('code' !== $this->stringParam($params, 'response_type')) {
            return $this->redirectError($redirectUri, 'unsupported_response_type', 'Only response_type=code is supported.', $state);
        }

        // 3. PKCE (S256 required)
        $codeChallenge = $this->stringParam($params, 'code_challenge');
        $codeChallengeMethod = $this->stringParam($params, 'code_challenge_method');
        if (null === $codeChallenge || Pkce::METHOD_S256 !== $codeChallengeMethod) {
            return $this->redirectError($redirectUri, 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', $state);
        }

        // 4. Scopes
        $scopes = $this->resolveScopes($params, $client->scopes);
        foreach ($scopes as $scope) {
            $allowedByServer = [] === $this->supportedScopes || \in_array($scope, $this->supportedScopes, true);
            if (!$allowedByServer || !$client->allowsScope($scope)) {
                return $this->redirectError($redirectUri, 'invalid_scope', \sprintf('The scope "%s" is not allowed.', $scope), $state);
            }
        }

        // 5. Resource owner (host authenticates the user)
        $owner = $this->resourceOwner->resolve($request);
        if (null === $owner) {
            return $this->resourceOwner->onUnauthenticated($request);
        }

        // 6. Consent
        $decision = $this->consent->decide($client, $scopes, $owner, $request);
        if (null !== $decision->response) {
            return $decision->response;
        }
        if (!$decision->approved) {
            return $this->redirectError($redirectUri, 'access_denied', 'The resource owner denied the request.', $state);
        }
        $scopes = $decision->approvedScopes ?? $scopes;

        // 7. Issue the authorization code
        $resource = $this->stringParam($params, 'resource');
        $code = $this->codeIssuer->issueCode($client, $owner, $redirectUri, $scopes, $codeChallenge, Pkce::METHOD_S256, $resource);

        $location = $this->appendQuery($redirectUri, array_filter([
            'code' => $code,
            'state' => $state,
        ], static fn (?string $v): bool => null !== $v));

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function collectParams(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();

        if ('POST' === $request->getMethod()
            && str_starts_with(strtolower($request->getHeaderLine('Content-Type')), 'application/x-www-form-urlencoded')) {
            parse_str($request->getBody()->__toString(), $body);
            $params = array_merge($params, $body);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $clientScopes
     *
     * @return list<string>
     */
    private function resolveScopes(array $params, array $clientScopes): array
    {
        $scope = $this->stringParam($params, 'scope');
        if (null === $scope) {
            return [] !== $clientScopes ? $clientScopes : $this->supportedScopes;
        }

        return array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => '' !== $s));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function stringParam(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $params
     */
    private function appendQuery(string $uri, array $params): string
    {
        if ([] === $params) {
            return $uri;
        }

        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query($params);
    }

    private function redirectError(string $redirectUri, string $error, string $description, ?string $state): ResponseInterface
    {
        $location = $this->appendQuery($redirectUri, array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ], static fn (?string $v): bool => null !== $v));

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $location)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function directError(int $status, string $error, string $description): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streamFactory->createStream(json_encode([
                'error' => $error,
                'error_description' => $description,
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)));
    }
}
