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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\SecureUrl;

/**
 * An authorization server's metadata document (RFC 8414 or OpenID Connect discovery).
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AuthorizationServerMetadata implements \JsonSerializable
{
    /**
     * @param string    $issuer                            the issuer identifier, which must match how the document was found
     * @param ?string   $authorizationEndpoint             where the user is sent to approve the request, when a supported grant uses it
     * @param string    $tokenEndpoint                     where codes and refresh tokens are exchanged
     * @param ?string   $registrationEndpoint              where a client can register itself, when the server allows it
     * @param string[]  $scopesSupported                   scopes the server knows about
     * @param ?string[] $grantTypesSupported               grant types the server accepts, or null when it does not say
     * @param string[]  $tokenEndpointAuthMethodsSupported client authentication methods the token endpoint accepts, empty when unstated, which RFC 8414 section 2 reads as client_secret_basic
     * @param string[]  $codeChallengeMethodsSupported     PKCE challenge methods the server accepts
     * @param bool      $clientIdMetadataDocumentSupported whether a URL may be used as the client id
     * @param bool      $issuerParameterSupported          whether authorization responses carry an "iss" parameter
     */
    public function __construct(
        public readonly string $issuer,
        public readonly ?string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly ?string $registrationEndpoint = null,
        public readonly array $scopesSupported = [],
        public readonly ?array $grantTypesSupported = null,
        public readonly array $tokenEndpointAuthMethodsSupported = [],
        public readonly array $codeChallengeMethodsSupported = [],
        public readonly bool $clientIdMetadataDocumentSupported = false,
        public readonly bool $issuerParameterSupported = false,
    ) {
        $parts = SecureUrl::parse($issuer, 'authorization server issuer');

        // RFC 8414 section 2 rules out a query and fragment; RFC 9110 section 4.2.4 treats untrusted userinfo as an error.
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(\sprintf('The authorization server issuer "%s" must not have a query, fragment or userinfo.', $issuer));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $grantTypes = isset($data['grant_types_supported']) ? self::strings($data['grant_types_supported']) : null;
        $requiredFields = ['issuer', 'token_endpoint'];

        // RFC 8414 section 2 requires this endpoint only when a supported grant uses it.
        if (null === $grantTypes || [] !== array_intersect($grantTypes, ['authorization_code', 'implicit'])) {
            $requiredFields[] = 'authorization_endpoint';
        }

        foreach ($requiredFields as $required) {
            if (!isset($data[$required]) || !\is_string($data[$required])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata is missing a string "%s".', $required));
            }
        }

        /** @var string $issuer */
        $issuer = $data['issuer'];
        $issuerOnLoopback = SecureUrl::isLoopback($issuer);
        $issuerIsPublic = !$issuerOnLoopback && !SecureUrl::isPrivateNetwork($issuer);

        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $endpoint) {
            if (!\is_string($data[$endpoint] ?? null)) {
                continue;
            }

            if (!self::isTransportSecure($data[$endpoint])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata declares "%s" as "%s", which is neither an HTTPS URL nor a loopback address.', $endpoint, $data[$endpoint]));
            }

            // Only a server on this machine may send the client back to this machine.
            if (!$issuerOnLoopback && SecureUrl::isLoopback($data[$endpoint])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata of "%s" declares "%s" as "%s", which is a loopback address.', $issuer, $endpoint, $data[$endpoint]));
            }

            // Nor may a server outside a private network send the client into one.
            if ($issuerIsPublic && SecureUrl::isPrivateNetwork($data[$endpoint])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata of "%s" declares "%s" as "%s", which is an address of a private network.', $issuer, $endpoint, $data[$endpoint]));
            }

            $parts = SecureUrl::parse($data[$endpoint], $endpoint);

            // RFC 6749 sections 3.1 and 3.2 rule out fragments; RFC 9110 section 4.2.4 treats untrusted userinfo as an error.
            if (isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata declares "%s" as "%s", which must not have a fragment or userinfo.', $endpoint, $data[$endpoint]));
            }
        }

        /* @var array{issuer: string, token_endpoint: string} $data */
        return new self(
            $data['issuer'],
            \is_string($data['authorization_endpoint'] ?? null) ? $data['authorization_endpoint'] : null,
            $data['token_endpoint'],
            \is_string($data['registration_endpoint'] ?? null) ? $data['registration_endpoint'] : null,
            self::strings($data['scopes_supported'] ?? null),
            $grantTypes,
            self::strings($data['token_endpoint_auth_methods_supported'] ?? null),
            self::strings($data['code_challenge_methods_supported'] ?? null),
            true === ($data['client_id_metadata_document_supported'] ?? null),
            true === ($data['authorization_response_iss_parameter_supported'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'registration_endpoint' => $this->registrationEndpoint,
            'scopes_supported' => $this->scopesSupported,
            'grant_types_supported' => $this->grantTypesSupported,
            'token_endpoint_auth_methods_supported' => $this->tokenEndpointAuthMethodsSupported,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
            'client_id_metadata_document_supported' => $this->clientIdMetadataDocumentSupported,
            'authorization_response_iss_parameter_supported' => $this->issuerParameterSupported,
        ], static fn (mixed $value): bool => null !== $value);
    }

    /**
     * The fixed endpoints a 2025-03-26 era server without metadata is assumed to expose.
     *
     * @throws InvalidArgumentException when the issuer is neither https nor loopback, or has a query, fragment or userinfo
     */
    public static function forLegacyServer(string $issuer): self
    {
        if (!self::isTransportSecure($issuer)) {
            throw new InvalidArgumentException(\sprintf('The legacy authorization server "%s" is neither an HTTPS URL nor a loopback address.', $issuer));
        }

        $base = rtrim($issuer, '/');

        // The 2025-03-26 revision requires PKCE of every server, and these have no list to read.
        return new self($issuer, $base.'/authorize', $base.'/token', $base.'/register', codeChallengeMethodsSupported: [Pkce::METHOD]);
    }

    public function supportsPkce(): bool
    {
        return \in_array(Pkce::METHOD, $this->codeChallengeMethodsSupported, true);
    }

    public function supportsGrant(string $grantType): bool
    {
        // RFC 8414 section 2 also defaults to implicit, which OAuth 2.1 removed.
        return null === $this->grantTypesSupported
            ? 'authorization_code' === $grantType
            : \in_array($grantType, $this->grantTypesSupported, true);
    }

    public function supportsScope(string $scope): bool
    {
        return \in_array($scope, $this->scopesSupported, true);
    }

    /**
     * Whether a URL is https or loopback, the only endpoints credentials may go to.
     */
    public static function isTransportSecure(string $url): bool
    {
        try {
            SecureUrl::parse($url, 'URL');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
