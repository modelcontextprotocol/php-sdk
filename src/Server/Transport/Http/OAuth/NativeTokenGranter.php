<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Mcp\Exception\OAuthException;
use Psr\Clock\ClockInterface;

/**
 * Default {@see TokenGranterInterface}: implements the authorization code grant
 * (with mandatory PKCE) and the refresh token grant (with rotation), minting
 * access tokens via an {@see AccessTokenIssuerInterface}.
 */
final class NativeTokenGranter implements TokenGranterInterface
{
    public function __construct(
        private readonly ClientRepositoryInterface $clients,
        private readonly AuthorizationCodeStoreInterface $codes,
        private readonly RefreshTokenStoreInterface $refreshTokens,
        private readonly AccessTokenIssuerInterface $issuer,
        private readonly ?string $resource = null,
        private readonly int $accessTokenTtl = 3600,
        private readonly ?int $refreshTokenTtl = 1209600,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function grant(string $grantType, array $params): TokenResponse
    {
        return match ($grantType) {
            'authorization_code' => $this->grantAuthorizationCode($params),
            'refresh_token' => $this->grantRefreshToken($params),
            '' => throw OAuthException::invalidRequest('Missing "grant_type" parameter.'),
            default => throw OAuthException::unsupportedGrantType(\sprintf('Unsupported grant type "%s".', $grantType)),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function grantAuthorizationCode(array $params): TokenResponse
    {
        $client = $this->authenticateClient($params);

        if (!$client->supportsGrant('authorization_code')) {
            throw OAuthException::unauthorizedClient('The client is not authorized to use the authorization code grant.');
        }

        $code = $this->requireParam($params, 'code');
        $redirectUri = $this->requireParam($params, 'redirect_uri');
        $codeVerifier = $this->requireParam($params, 'code_verifier');

        $authorizationCode = $this->codes->consume($code);
        if (null === $authorizationCode) {
            throw OAuthException::invalidGrant('The authorization code is invalid or has already been used.');
        }

        if ($authorizationCode->isExpired($this->now())) {
            throw OAuthException::invalidGrant('The authorization code has expired.');
        }

        if (!hash_equals($authorizationCode->clientId, $client->clientId)) {
            throw OAuthException::invalidGrant('The authorization code was issued to another client.');
        }

        if (!hash_equals($authorizationCode->redirectUri, $redirectUri)) {
            throw OAuthException::invalidGrant('The redirect URI does not match the authorization request.');
        }

        if (!Pkce::verify($codeVerifier, $authorizationCode->codeChallenge)) {
            throw OAuthException::invalidGrant('The PKCE code verifier is invalid.');
        }

        return $this->issueTokens(
            $client,
            $authorizationCode->userId,
            $authorizationCode->scopes,
            $authorizationCode->userClaims,
            $authorizationCode->resource,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function grantRefreshToken(array $params): TokenResponse
    {
        $client = $this->authenticateClient($params);

        if (!$client->supportsGrant('refresh_token')) {
            throw OAuthException::unauthorizedClient('The client is not authorized to use the refresh token grant.');
        }

        $token = $this->requireParam($params, 'refresh_token');

        $refreshToken = $this->refreshTokens->consume($token);
        if (null === $refreshToken) {
            throw OAuthException::invalidGrant('The refresh token is invalid or has already been used.');
        }

        if ($refreshToken->isExpired($this->now())) {
            throw OAuthException::invalidGrant('The refresh token has expired.');
        }

        if (!hash_equals($refreshToken->clientId, $client->clientId)) {
            throw OAuthException::invalidGrant('The refresh token was issued to another client.');
        }

        $scopes = $this->resolveRefreshScopes($params, $refreshToken->scopes);

        return $this->issueTokens(
            $client,
            $refreshToken->userId,
            $scopes,
            $refreshToken->userClaims,
            $refreshToken->resource,
        );
    }

    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $userClaims
     */
    private function issueTokens(Client $client, string $userId, array $scopes, array $userClaims, ?string $resource): TokenResponse
    {
        $audience = $resource ?? $this->resource ?? $client->clientId;

        $accessToken = $this->issuer->issue($userId, $audience, $scopes, $client->clientId, $this->accessTokenTtl, $userClaims);

        $refreshTokenValue = null;
        if (null !== $this->refreshTokenTtl && $client->supportsGrant('refresh_token')) {
            $refreshTokenValue = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

            $this->refreshTokens->store($refreshTokenValue, new RefreshToken(
                clientId: $client->clientId,
                userId: $userId,
                scopes: $scopes,
                userClaims: $userClaims,
                resource: $resource,
                expiresAt: $this->now()->modify(\sprintf('+%d seconds', $this->refreshTokenTtl)),
            ));
        }

        return new TokenResponse($accessToken['token'], $this->accessTokenTtl, $scopes, $refreshTokenValue);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function authenticateClient(array $params): Client
    {
        $clientId = $params['client_id'] ?? null;
        if (!\is_string($clientId) || '' === $clientId) {
            throw OAuthException::invalidClient('Client authentication failed: missing client_id.');
        }

        $client = $this->clients->find($clientId);
        if (null === $client) {
            throw OAuthException::invalidClient('Client authentication failed: unknown client.');
        }

        if (!$client->isPublic()) {
            $secret = $params['client_secret'] ?? null;
            if (!\is_string($secret) || '' === $secret || !hash_equals((string) $client->clientSecret, $secret)) {
                throw OAuthException::invalidClient('Client authentication failed: invalid client secret.');
            }
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $grantedScopes
     *
     * @return list<string>
     */
    private function resolveRefreshScopes(array $params, array $grantedScopes): array
    {
        $requested = $params['scope'] ?? null;
        if (!\is_string($requested) || '' === trim($requested)) {
            return $grantedScopes;
        }

        $requestedScopes = array_values(array_filter(explode(' ', $requested), static fn (string $s): bool => '' !== $s));

        foreach ($requestedScopes as $scope) {
            if (!\in_array($scope, $grantedScopes, true)) {
                throw OAuthException::invalidScope(\sprintf('The requested scope "%s" exceeds the originally granted scope.', $scope));
            }
        }

        return $requestedScopes;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requireParam(array $params, string $name): string
    {
        $value = $params[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw OAuthException::invalidRequest(\sprintf('Missing or invalid "%s" parameter.', $name));
        }

        return $value;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }
}
