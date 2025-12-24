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
 * OAuth 2.0 Client Registration Response (RFC 7591/7592).
 *
 * Contains the registered client information returned by the authorization server.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591#section-3.2
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ClientRegistrationResponse
{
    /**
     * @param string                   $clientId                    The unique client identifier
     * @param string|null              $clientSecret                The client secret (for confidential clients)
     * @param int|null                 $clientIdIssuedAt            Timestamp when client_id was issued
     * @param int|null                 $clientSecretExpiresAt       Timestamp when client_secret expires (0 = never)
     * @param string|null              $registrationAccessToken     Token for managing registration (RFC 7592)
     * @param string|null              $registrationClientUri       URI for managing registration (RFC 7592)
     * @param string[]                 $redirectUris                Registered redirect URIs
     * @param string|null              $clientName                  Human-readable client name
     * @param string|null              $clientUri                   URL of the client's home page
     * @param string|null              $logoUri                     URL of the client's logo
     * @param string[]                 $grantTypes                  Allowed grant types
     * @param string[]                 $responseTypes               Allowed response types
     * @param string|null              $scope                       Allowed scope
     * @param string                   $tokenEndpointAuthMethod     Token endpoint auth method
     * @param array<string, mixed>     $additionalFields            Any additional fields from the response
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        public readonly ?int $clientIdIssuedAt = null,
        public readonly ?int $clientSecretExpiresAt = null,
        public readonly ?string $registrationAccessToken = null,
        public readonly ?string $registrationClientUri = null,
        public readonly array $redirectUris = [],
        public readonly ?string $clientName = null,
        public readonly ?string $clientUri = null,
        public readonly ?string $logoUri = null,
        public readonly array $grantTypes = [],
        public readonly array $responseTypes = [],
        public readonly ?string $scope = null,
        public readonly string $tokenEndpointAuthMethod = 'none',
        public readonly array $additionalFields = [],
    ) {
    }

    /**
     * Check if this is a public client (no secret).
     */
    public function isPublicClient(): bool
    {
        return null === $this->clientSecret;
    }

    /**
     * Check if the client secret has expired.
     */
    public function isSecretExpired(): bool
    {
        if (null === $this->clientSecretExpiresAt || $this->clientSecretExpiresAt === 0) {
            return false;
        }

        return time() > $this->clientSecretExpiresAt;
    }

    /**
     * Check if client registration management is supported (RFC 7592).
     */
    public function supportsManagement(): bool
    {
        return null !== $this->registrationAccessToken && null !== $this->registrationClientUri;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $knownFields = [
            'client_id', 'client_secret', 'client_id_issued_at', 'client_secret_expires_at',
            'registration_access_token', 'registration_client_uri', 'redirect_uris',
            'client_name', 'client_uri', 'logo_uri', 'grant_types', 'response_types',
            'scope', 'token_endpoint_auth_method',
        ];

        $additionalFields = array_diff_key($data, array_flip($knownFields));

        return new self(
            clientId: $data['client_id'] ?? throw new \InvalidArgumentException('Missing client_id'),
            clientSecret: $data['client_secret'] ?? null,
            clientIdIssuedAt: isset($data['client_id_issued_at']) ? (int) $data['client_id_issued_at'] : null,
            clientSecretExpiresAt: isset($data['client_secret_expires_at']) ? (int) $data['client_secret_expires_at'] : null,
            registrationAccessToken: $data['registration_access_token'] ?? null,
            registrationClientUri: $data['registration_client_uri'] ?? null,
            redirectUris: $data['redirect_uris'] ?? [],
            clientName: $data['client_name'] ?? null,
            clientUri: $data['client_uri'] ?? null,
            logoUri: $data['logo_uri'] ?? null,
            grantTypes: $data['grant_types'] ?? [],
            responseTypes: $data['response_types'] ?? [],
            scope: $data['scope'] ?? null,
            tokenEndpointAuthMethod: $data['token_endpoint_auth_method'] ?? 'none',
            additionalFields: $additionalFields,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'client_id' => $this->clientId,
        ];

        if (null !== $this->clientSecret) {
            $data['client_secret'] = $this->clientSecret;
        }

        if (null !== $this->clientIdIssuedAt) {
            $data['client_id_issued_at'] = $this->clientIdIssuedAt;
        }

        if (null !== $this->clientSecretExpiresAt) {
            $data['client_secret_expires_at'] = $this->clientSecretExpiresAt;
        }

        if (null !== $this->registrationAccessToken) {
            $data['registration_access_token'] = $this->registrationAccessToken;
        }

        if (null !== $this->registrationClientUri) {
            $data['registration_client_uri'] = $this->registrationClientUri;
        }

        if (!empty($this->redirectUris)) {
            $data['redirect_uris'] = $this->redirectUris;
        }

        if (null !== $this->clientName) {
            $data['client_name'] = $this->clientName;
        }

        if (null !== $this->clientUri) {
            $data['client_uri'] = $this->clientUri;
        }

        if (null !== $this->logoUri) {
            $data['logo_uri'] = $this->logoUri;
        }

        if (!empty($this->grantTypes)) {
            $data['grant_types'] = $this->grantTypes;
        }

        if (!empty($this->responseTypes)) {
            $data['response_types'] = $this->responseTypes;
        }

        if (null !== $this->scope) {
            $data['scope'] = $this->scope;
        }

        $data['token_endpoint_auth_method'] = $this->tokenEndpointAuthMethod;

        return array_merge($data, $this->additionalFields);
    }
}

