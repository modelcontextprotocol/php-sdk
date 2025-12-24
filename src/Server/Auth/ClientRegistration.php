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
 * OAuth 2.0 Client Registration Metadata (RFC 7591).
 *
 * Represents the metadata for registering an OAuth client dynamically.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591#section-2
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ClientRegistration implements \JsonSerializable
{
    /**
     * @param string[]    $redirectUris             Array of redirect URIs
     * @param string|null $clientName               Human-readable client name
     * @param string|null $clientUri                URL of the client's home page
     * @param string|null $logoUri                  URL of the client's logo
     * @param string[]    $grantTypes               Array of grant types (default: authorization_code)
     * @param string[]    $responseTypes            Array of response types (default: code)
     * @param string|null $scope                    Space-delimited scope string
     * @param string[]    $contacts                 Array of contact emails
     * @param string|null $tosUri                   URL of terms of service
     * @param string|null $policyUri                URL of privacy policy
     * @param string|null $jwksUri                  URL of client's JWKS
     * @param array<string, mixed>|null $jwks       Client's JWKS (inline)
     * @param string|null $softwareId               Unique identifier for the client software
     * @param string|null $softwareVersion          Version of the client software
     * @param string      $tokenEndpointAuthMethod  Token endpoint auth method (none, client_secret_basic, client_secret_post, private_key_jwt)
     */
    public function __construct(
        public readonly array $redirectUris,
        public readonly ?string $clientName = null,
        public readonly ?string $clientUri = null,
        public readonly ?string $logoUri = null,
        public readonly array $grantTypes = ['authorization_code'],
        public readonly array $responseTypes = ['code'],
        public readonly ?string $scope = null,
        public readonly array $contacts = [],
        public readonly ?string $tosUri = null,
        public readonly ?string $policyUri = null,
        public readonly ?string $jwksUri = null,
        public readonly ?array $jwks = null,
        public readonly ?string $softwareId = null,
        public readonly ?string $softwareVersion = null,
        public readonly string $tokenEndpointAuthMethod = 'none',
    ) {
        if (empty($redirectUris)) {
            throw new \InvalidArgumentException('At least one redirect URI is required');
        }
    }

    /**
     * Create a registration for a public MCP client (typical for MCP).
     *
     * @param string[] $redirectUris Redirect URIs (typically localhost for native apps)
     */
    public static function forPublicClient(
        array $redirectUris,
        string $clientName,
        ?string $clientUri = null,
        ?string $scope = null,
    ): self {
        return new self(
            redirectUris: $redirectUris,
            clientName: $clientName,
            clientUri: $clientUri,
            grantTypes: ['authorization_code', 'refresh_token'],
            responseTypes: ['code'],
            scope: $scope,
            tokenEndpointAuthMethod: 'none',
        );
    }

    /**
     * Create a registration for a confidential client.
     *
     * @param string[] $redirectUris Redirect URIs
     */
    public static function forConfidentialClient(
        array $redirectUris,
        string $clientName,
        ?string $clientUri = null,
        ?string $scope = null,
        string $tokenEndpointAuthMethod = 'client_secret_basic',
    ): self {
        return new self(
            redirectUris: $redirectUris,
            clientName: $clientName,
            clientUri: $clientUri,
            grantTypes: ['authorization_code', 'refresh_token', 'client_credentials'],
            responseTypes: ['code'],
            scope: $scope,
            tokenEndpointAuthMethod: $tokenEndpointAuthMethod,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'redirect_uris' => $this->redirectUris,
            'grant_types' => $this->grantTypes,
            'response_types' => $this->responseTypes,
            'token_endpoint_auth_method' => $this->tokenEndpointAuthMethod,
        ];

        if (null !== $this->clientName) {
            $data['client_name'] = $this->clientName;
        }

        if (null !== $this->clientUri) {
            $data['client_uri'] = $this->clientUri;
        }

        if (null !== $this->logoUri) {
            $data['logo_uri'] = $this->logoUri;
        }

        if (null !== $this->scope) {
            $data['scope'] = $this->scope;
        }

        if (!empty($this->contacts)) {
            $data['contacts'] = $this->contacts;
        }

        if (null !== $this->tosUri) {
            $data['tos_uri'] = $this->tosUri;
        }

        if (null !== $this->policyUri) {
            $data['policy_uri'] = $this->policyUri;
        }

        if (null !== $this->jwksUri) {
            $data['jwks_uri'] = $this->jwksUri;
        }

        if (null !== $this->jwks) {
            $data['jwks'] = $this->jwks;
        }

        if (null !== $this->softwareId) {
            $data['software_id'] = $this->softwareId;
        }

        if (null !== $this->softwareVersion) {
            $data['software_version'] = $this->softwareVersion;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            redirectUris: $data['redirect_uris'] ?? [],
            clientName: $data['client_name'] ?? null,
            clientUri: $data['client_uri'] ?? null,
            logoUri: $data['logo_uri'] ?? null,
            grantTypes: $data['grant_types'] ?? ['authorization_code'],
            responseTypes: $data['response_types'] ?? ['code'],
            scope: $data['scope'] ?? null,
            contacts: $data['contacts'] ?? [],
            tosUri: $data['tos_uri'] ?? null,
            policyUri: $data['policy_uri'] ?? null,
            jwksUri: $data['jwks_uri'] ?? null,
            jwks: $data['jwks'] ?? null,
            softwareId: $data['software_id'] ?? null,
            softwareVersion: $data['software_version'] ?? null,
            tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? 'none',
        );
    }
}

