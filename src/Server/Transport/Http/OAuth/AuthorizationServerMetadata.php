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

use Mcp\Exception\InvalidArgumentException;

/**
 * OAuth 2.0 Authorization Server Metadata (RFC 8414).
 *
 * Endpoints default to conventional paths derived from the issuer. The
 * registration_endpoint is intentionally left out so that
 * {@see \Mcp\Server\Transport\Http\Middleware\ClientRegistrationMiddleware} can
 * enrich the served document when dynamic client registration is enabled.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 */
final class AuthorizationServerMetadata implements \JsonSerializable
{
    public const DEFAULT_METADATA_PATH = '/.well-known/oauth-authorization-server';

    private string $issuer;
    private string $authorizationEndpoint;
    private string $tokenEndpoint;
    private string $jwksUri;
    private ?string $registrationEndpoint;

    /** @var list<string> */
    private array $scopesSupported;

    /** @var list<string> */
    private array $responseTypesSupported;

    /** @var list<string> */
    private array $grantTypesSupported;

    /** @var list<string> */
    private array $codeChallengeMethodsSupported;

    /** @var list<string> */
    private array $tokenEndpointAuthMethodsSupported;

    /** @var array<string, mixed> */
    private array $extra;

    /**
     * @param list<string>         $scopesSupported
     * @param list<string>         $responseTypesSupported
     * @param list<string>         $grantTypesSupported
     * @param list<string>         $codeChallengeMethodsSupported
     * @param list<string>         $tokenEndpointAuthMethodsSupported
     * @param array<string, mixed> $extra
     */
    public function __construct(
        string $issuer,
        ?string $authorizationEndpoint = null,
        ?string $tokenEndpoint = null,
        ?string $jwksUri = null,
        ?string $registrationEndpoint = null,
        array $scopesSupported = ['mcp:tools', 'mcp:resources'],
        array $responseTypesSupported = ['code'],
        array $grantTypesSupported = ['authorization_code', 'refresh_token'],
        array $codeChallengeMethodsSupported = ['S256'],
        array $tokenEndpointAuthMethodsSupported = ['client_secret_basic', 'client_secret_post', 'none'],
        array $extra = [],
        private readonly string $metadataPath = self::DEFAULT_METADATA_PATH,
    ) {
        $issuer = rtrim(trim($issuer), '/');
        if ('' === $issuer) {
            throw new InvalidArgumentException('Authorization server metadata requires a non-empty issuer.');
        }

        $this->issuer = $issuer;
        $this->authorizationEndpoint = $authorizationEndpoint ?? $issuer.'/authorize';
        $this->tokenEndpoint = $tokenEndpoint ?? $issuer.'/token';
        $this->jwksUri = $jwksUri ?? $issuer.'/.well-known/jwks.json';
        $this->registrationEndpoint = $registrationEndpoint;
        $this->scopesSupported = array_values($scopesSupported);
        $this->responseTypesSupported = array_values($responseTypesSupported);
        $this->grantTypesSupported = array_values($grantTypesSupported);
        $this->codeChallengeMethodsSupported = array_values($codeChallengeMethodsSupported);
        $this->tokenEndpointAuthMethodsSupported = array_values($tokenEndpointAuthMethodsSupported);
        $this->extra = $extra;
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getMetadataPath(): string
    {
        return $this->metadataPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'jwks_uri' => $this->jwksUri,
            'scopes_supported' => $this->scopesSupported,
            'response_types_supported' => $this->responseTypesSupported,
            'grant_types_supported' => $this->grantTypesSupported,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethodsSupported,
        ];

        if (null !== $this->registrationEndpoint) {
            $data['registration_endpoint'] = $this->registrationEndpoint;
        }

        return array_merge($this->extra, $data);
    }
}
