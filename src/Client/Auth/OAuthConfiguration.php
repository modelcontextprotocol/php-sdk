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
 * What the application decided about authorization before the first request.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuthConfiguration
{
    /**
     * @param ClientMetadata  $clientMetadata    what the client says about itself when registering
     * @param string          $redirectUri       where the authorization server sends the user back
     * @param Grant           $grant             the grant to drive
     * @param ?string         $clientId          a client id issued out of band, skipping registration
     * @param ?string         $clientSecret      the matching secret, when there is one
     * @param ?string         $clientMetadataUrl a URL serving the client metadata document, usable as a
     *                                           client id against servers that accept one
     * @param ?string         $privateKeyPem     a PEM private key for `private_key_jwt` client authentication
     * @param string          $signingAlgorithm  the algorithm that key is used with
     * @param string[]        $scopes            scopes to request regardless of what is advertised
     * @param bool            $offlineAccess     whether to ask for a refresh token where the server offers one
     * @param bool            $legacyDiscovery   whether to fall back to pre-2025-06-18 discovery when a
     *                                           server publishes no protected resource metadata
     * @param ?CrossAppAccess $crossAppAccess    the enterprise identity to reach the server through,
     *                                           instead of asking the user to approve anything
     * @param ?string         $expectedIssuer    the only authorization server to authorize with
     *
     * @throws InvalidArgumentException when the redirect URI is insecure, a client id, secret or private key is set without an
     *                                  expected issuer, or the grant, cross-app access and signing algorithm don't fit together
     */
    public function __construct(
        public readonly ClientMetadata $clientMetadata,
        public readonly string $redirectUri = OAuth::DEFAULT_REDIRECT_URI,
        public readonly Grant $grant = Grant::AuthorizationCode,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $clientMetadataUrl = null,
        public readonly ?string $privateKeyPem = null,
        public readonly string $signingAlgorithm = 'RS256',
        public readonly array $scopes = [],
        public readonly bool $offlineAccess = true,
        public readonly bool $legacyDiscovery = false,
        public readonly ?CrossAppAccess $crossAppAccess = null,
        public readonly ?string $expectedIssuer = null,
    ) {
        if (!AuthorizationServerMetadata::isTransportSecure($redirectUri)) {
            throw new InvalidArgumentException(\sprintf('The redirect URI "%s" must use https, or http on a loopback host.', $redirectUri));
        }

        // RFC 6749 section 3.1.2 rules out a fragment, and userinfo has no meaning in a redirect.
        $parts = parse_url($redirectUri);

        if (!\is_array($parts) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(\sprintf('The redirect URI "%s" must not have a fragment or userinfo.', $redirectUri));
        }

        if (!\in_array($grant, [Grant::AuthorizationCode, Grant::ClientCredentials, Grant::JwtBearer], true)) {
            throw new InvalidArgumentException(\sprintf('The "%s" grant cannot be used to authorize a client.', $grant->value));
        }

        if ((Grant::JwtBearer === $grant) !== (null !== $crossAppAccess)) {
            throw new InvalidArgumentException('The JWT bearer grant and cross-app access only work together. Call OAuth::setCrossAppAccess().');
        }

        if (!\in_array($signingAlgorithm, TokenEndpoint::ASSERTION_ALGORITHMS, true)) {
            throw new InvalidArgumentException(\sprintf('Unsupported client assertion algorithm "%s". Use one of %s.', $signingAlgorithm, implode(', ', TokenEndpoint::ASSERTION_ALGORITHMS)));
        }

        if (Grant::ClientCredentials === $grant && (null === $clientId || (null === $clientSecret && null === $privateKeyPem))) {
            throw new InvalidArgumentException('The client credentials grant needs a client id and either a client secret or a private key.');
        }

        if (null !== $crossAppAccess && null === $clientId) {
            throw new InvalidArgumentException('Cross-app access needs the client id the authorization server knows this client by; it is what the authorization grant is bound to.');
        }

        if ((null !== $clientId || null !== $clientSecret || null !== $privateKeyPem) && null === $expectedIssuer) {
            throw new InvalidArgumentException('A configured client needs an expected issuer, or a hostile MCP server could receive its authorization codes and secret. Call OAuth::setExpectedIssuer().');
        }

        if (null !== $privateKeyPem && null === $clientId) {
            throw new InvalidArgumentException('Private key JWT client authentication needs a client id to name in the assertion.');
        }
    }
}
