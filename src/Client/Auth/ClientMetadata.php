<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

/**
 * What the client tells an authorization server about itself.
 *
 * The same document is posted to a registration endpoint (RFC 7591) and published at a
 * URL when the server accepts client id metadata documents, so it is modelled once and
 * serialised the same way for both.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ClientMetadata implements \JsonSerializable
{
    /**
     * @param string   $clientName      the name shown to the user on the consent screen
     * @param string[] $redirectUris    where the authorization server may send the user back
     * @param string[] $grantTypes      the grants the client intends to use
     * @param string   $applicationType "native" for anything running on the user's machine,
     *                                  "web" for a server-side application (SEP-837)
     * @param ?string  $clientUri       a page describing the client
     * @param ?string  $softwareId      a stable identifier for the client software
     * @param ?string  $softwareVersion the version of that software
     */
    public function __construct(
        public readonly string $clientName,
        public readonly array $redirectUris = [],
        public readonly array $grantTypes = ['authorization_code', 'refresh_token'],
        public readonly string $applicationType = 'native',
        public readonly ?string $clientUri = null,
        public readonly ?string $softwareId = null,
        public readonly ?string $softwareVersion = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(TokenEndpointAuthMethod $authMethod, ?string $scope = null): array
    {
        $usesAuthorizationCode = \in_array('authorization_code', $this->grantTypes, true);

        return array_filter([
            'client_name' => $this->clientName,
            'redirect_uris' => $usesAuthorizationCode ? $this->redirectUris : [],
            'grant_types' => $this->grantTypes,
            'response_types' => $usesAuthorizationCode ? ['code'] : [],
            'token_endpoint_auth_method' => $authMethod->value,
            'application_type' => $this->applicationType,
            'client_uri' => $this->clientUri,
            'software_id' => $this->softwareId,
            'software_version' => $this->softwareVersion,
            'scope' => $scope,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(TokenEndpointAuthMethod::None);
    }

    public function withGrantTypes(string ...$grantTypes): self
    {
        return new self($this->clientName, $this->redirectUris, $grantTypes, $this->applicationType, $this->clientUri, $this->softwareId, $this->softwareVersion);
    }
}
