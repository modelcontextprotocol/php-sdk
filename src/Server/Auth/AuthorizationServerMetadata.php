<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Auth;

/**
 * OAuth 2.0 Authorization Server Metadata (RFC 8414).
 *
 * Represents the metadata document from an authorization server.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8414
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class AuthorizationServerMetadata
{
    /**
     * @param string        $issuer                             Authorization server's issuer identifier
     * @param string        $authorizationEndpoint              URL of the authorization endpoint
     * @param string|null   $tokenEndpoint                      URL of the token endpoint
     * @param string|null   $jwksUri                            URL of the server's JWKS
     * @param string|null   $registrationEndpoint               URL for dynamic client registration
     * @param string[]|null $scopesSupported                    Supported scopes
     * @param string[]      $responseTypesSupported             Supported response types
     * @param string[]|null $responseModesSupported             Supported response modes
     * @param string[]|null $grantTypesSupported                Supported grant types
     * @param string[]|null $tokenEndpointAuthMethodsSupported  Token endpoint auth methods
     * @param string[]|null $codeChallengeMethodsSupported      PKCE code challenge methods
     * @param string|null   $introspectionEndpoint              Token introspection endpoint
     * @param string|null   $revocationEndpoint                 Token revocation endpoint
     * @param string|null   $userinfoEndpoint                   OIDC userinfo endpoint
     * @param bool          $clientIdMetadataDocumentSupported  Whether client ID metadata documents are supported
     * @param array<string, mixed> $additionalFields            Any additional metadata fields
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly ?string $tokenEndpoint = null,
        public readonly ?string $jwksUri = null,
        public readonly ?string $registrationEndpoint = null,
        public readonly ?array $scopesSupported = null,
        public readonly array $responseTypesSupported = ['code'],
        public readonly ?array $responseModesSupported = null,
        public readonly ?array $grantTypesSupported = null,
        public readonly ?array $tokenEndpointAuthMethodsSupported = null,
        public readonly ?array $codeChallengeMethodsSupported = null,
        public readonly ?string $introspectionEndpoint = null,
        public readonly ?string $revocationEndpoint = null,
        public readonly ?string $userinfoEndpoint = null,
        public readonly bool $clientIdMetadataDocumentSupported = false,
        public readonly array $additionalFields = [],
    ) {
    }

    /**
     * Check if PKCE is supported (required by MCP spec).
     */
    public function supportsPkce(): bool
    {
        return null !== $this->codeChallengeMethodsSupported
            && !empty($this->codeChallengeMethodsSupported);
    }

    /**
     * Check if S256 PKCE method is supported (required by MCP spec when technically capable).
     */
    public function supportsS256(): bool
    {
        return null !== $this->codeChallengeMethodsSupported
            && in_array('S256', $this->codeChallengeMethodsSupported, true);
    }

    /**
     * Check if dynamic client registration is supported.
     */
    public function supportsDynamicRegistration(): bool
    {
        return null !== $this->registrationEndpoint;
    }

    /**
     * Check if Client ID Metadata Documents are supported.
     */
    public function supportsClientIdMetadataDocument(): bool
    {
        return $this->clientIdMetadataDocumentSupported;
    }

    /**
     * Check if a specific grant type is supported.
     */
    public function supportsGrantType(string $grantType): bool
    {
        // Default grant types per RFC 8414
        $supported = $this->grantTypesSupported ?? ['authorization_code', 'implicit'];

        return in_array($grantType, $supported, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $knownFields = [
            'issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri',
            'registration_endpoint', 'scopes_supported', 'response_types_supported',
            'response_modes_supported', 'grant_types_supported',
            'token_endpoint_auth_methods_supported', 'code_challenge_methods_supported',
            'introspection_endpoint', 'revocation_endpoint', 'userinfo_endpoint',
            'client_id_metadata_document_supported',
        ];

        $additionalFields = array_diff_key($data, array_flip($knownFields));

        return new self(
            issuer: $data['issuer'] ?? throw new \InvalidArgumentException('Missing issuer'),
            authorizationEndpoint: $data['authorization_endpoint'] ?? throw new \InvalidArgumentException('Missing authorization_endpoint'),
            tokenEndpoint: $data['token_endpoint'] ?? null,
            jwksUri: $data['jwks_uri'] ?? null,
            registrationEndpoint: $data['registration_endpoint'] ?? null,
            scopesSupported: $data['scopes_supported'] ?? null,
            responseTypesSupported: $data['response_types_supported'] ?? ['code'],
            responseModesSupported: $data['response_modes_supported'] ?? null,
            grantTypesSupported: $data['grant_types_supported'] ?? null,
            tokenEndpointAuthMethodsSupported: $data['token_endpoint_auth_methods_supported'] ?? null,
            codeChallengeMethodsSupported: $data['code_challenge_methods_supported'] ?? null,
            introspectionEndpoint: $data['introspection_endpoint'] ?? null,
            revocationEndpoint: $data['revocation_endpoint'] ?? null,
            userinfoEndpoint: $data['userinfo_endpoint'] ?? null,
            clientIdMetadataDocumentSupported: $data['client_id_metadata_document_supported'] ?? false,
            additionalFields: $additionalFields,
        );
    }
}

