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

/**
 * An authorization server's metadata document (RFC 8414, or the OpenID Connect
 * discovery document that carries the same fields).
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AuthorizationServerMetadata
{
    /**
     * @param string   $issuer                            the issuer identifier, which must match how the document was found
     * @param string   $authorizationEndpoint             where the user is sent to approve the request
     * @param string   $tokenEndpoint                     where codes and refresh tokens are exchanged
     * @param ?string  $registrationEndpoint              where a client can register itself, when the server allows it
     * @param string[] $scopesSupported                   scopes the server knows about
     * @param string[] $grantTypesSupported               grant types the server accepts
     * @param string[] $tokenEndpointAuthMethodsSupported client authentication methods the token endpoint accepts
     * @param string[] $codeChallengeMethodsSupported     PKCE challenge methods the server accepts
     * @param bool     $clientIdMetadataDocumentSupported whether a URL may be used as the client id
     * @param bool     $issuerParameterSupported          whether authorization responses carry an "iss" parameter
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly ?string $registrationEndpoint = null,
        public readonly array $scopesSupported = [],
        public readonly array $grantTypesSupported = [],
        public readonly array $tokenEndpointAuthMethodsSupported = [],
        public readonly array $codeChallengeMethodsSupported = [],
        public readonly bool $clientIdMetadataDocumentSupported = false,
        public readonly bool $issuerParameterSupported = false,
    ) {
        if ('' === trim($issuer)) {
            throw new InvalidArgumentException('The authorization server metadata must name an issuer.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint'] as $required) {
            if (!isset($data[$required]) || !\is_string($data[$required])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata is missing a string "%s".', $required));
            }
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $endpoint) {
            if (\is_string($data[$endpoint] ?? null) && !self::isTransportSecure($data[$endpoint])) {
                throw new InvalidArgumentException(\sprintf('The authorization server metadata declares "%s" as "%s", which is neither an HTTPS URL nor a loopback address.', $endpoint, $data[$endpoint]));
            }
        }

        /* @var array{issuer: string, authorization_endpoint: string, token_endpoint: string} $data */
        return new self(
            $data['issuer'],
            $data['authorization_endpoint'],
            $data['token_endpoint'],
            \is_string($data['registration_endpoint'] ?? null) ? $data['registration_endpoint'] : null,
            self::strings($data['scopes_supported'] ?? null),
            self::strings($data['grant_types_supported'] ?? null),
            self::strings($data['token_endpoint_auth_methods_supported'] ?? null),
            self::strings($data['code_challenge_methods_supported'] ?? null),
            true === ($data['client_id_metadata_document_supported'] ?? null),
            true === ($data['authorization_response_iss_parameter_supported'] ?? null),
        );
    }

    /**
     * The endpoints a 2025-03-26 era server is assumed to expose when it publishes no
     * metadata at all: the fixed paths that revision of the specification named.
     */
    public static function forLegacyServer(string $issuer): self
    {
        $base = rtrim($issuer, '/');

        return new self($issuer, $base.'/authorize', $base.'/token', $base.'/register');
    }

    public function supportsGrant(string $grantType): bool
    {
        // An omitted grant_types_supported defaults to authorization_code and implicit
        // (RFC 8414 section 2); anything else has to be advertised to be usable.
        return [] === $this->grantTypesSupported
            ? 'authorization_code' === $grantType
            : \in_array($grantType, $this->grantTypesSupported, true);
    }

    public function supportsScope(string $scope): bool
    {
        return \in_array($scope, $this->scopesSupported, true);
    }

    /**
     * Whether an endpoint is one this client is willing to send credentials to, or send
     * the user's browser to.
     *
     * OAuth 2.1 requires TLS on every endpoint. The exception is a loopback address,
     * which never leaves the machine and is how local development and test servers are
     * reached -- everything else has to be https, because these URLs come out of a
     * document whose location a hostile MCP server chose.
     *
     * Public because the same question has to be asked of an issuer identifier *before*
     * its document is fetched: a check that only runs on the response has already let
     * the request out.
     */
    public static function isTransportSecure(string $url): bool
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if ('https' === strtolower($parts['scheme'])) {
            return true;
        }

        return 'http' === strtolower($parts['scheme']) && self::isLoopback($parts['host']);
    }

    private static function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        return 'localhost' === $host
            || '::1' === $host
            || 1 === preg_match('/^127(?:\.\d{1,3}){3}$/', $host);
    }

    /**
     * @return string[]
     */
    private static function strings(mixed $value): array
    {
        return \is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
