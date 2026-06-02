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

/**
 * An OAuth 2.0 client registration.
 *
 * A client is "public" when it has no secret (or its token endpoint auth method
 * is "none") and must therefore use PKCE; otherwise it is "confidential" and the
 * secret is verified at the token endpoint.
 */
final class Client
{
    public const AUTH_METHOD_NONE = 'none';
    public const AUTH_METHOD_CLIENT_SECRET_BASIC = 'client_secret_basic';
    public const AUTH_METHOD_CLIENT_SECRET_POST = 'client_secret_post';

    /** @var list<string> */
    public readonly array $redirectUris;

    /** @var list<string> */
    public readonly array $grantTypes;

    /** @var list<string> */
    public readonly array $scopes;

    /**
     * @param list<string> $redirectUris
     * @param list<string> $grantTypes
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        array $redirectUris = [],
        array $grantTypes = ['authorization_code', 'refresh_token'],
        array $scopes = [],
        public readonly string $tokenEndpointAuthMethod = self::AUTH_METHOD_CLIENT_SECRET_BASIC,
        public readonly ?string $clientName = null,
    ) {
        $this->redirectUris = array_values($redirectUris);
        $this->grantTypes = array_values($grantTypes);
        $this->scopes = array_values($scopes);
    }

    public function isPublic(): bool
    {
        return self::AUTH_METHOD_NONE === $this->tokenEndpointAuthMethod || null === $this->clientSecret;
    }

    public function hasRedirectUri(string $redirectUri): bool
    {
        return \in_array($redirectUri, $this->redirectUris, true);
    }

    public function supportsGrant(string $grantType): bool
    {
        return \in_array($grantType, $this->grantTypes, true);
    }

    public function allowsScope(string $scope): bool
    {
        return [] === $this->scopes || \in_array($scope, $this->scopes, true);
    }
}
