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
 * Everything about how this client wants to authorize, decided before the first request.
 *
 * Built by {@see OAuth}; nothing here is discovered at runtime, which is what keeps the
 * discovered half -- issuers, endpoints, scopes, tokens -- clearly separate from the
 * half the application chose.
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
     * @param ?string         $resource          the canonical resource URI to request tokens for, when it
     *                                           should not be derived from the endpoint being called
     * @param ?CrossAppAccess $crossAppAccess    the enterprise identity to reach the server through,
     *                                           instead of asking the user to approve anything
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
        public readonly ?string $resource = null,
        public readonly ?CrossAppAccess $crossAppAccess = null,
    ) {
        if (Grant::ClientCredentials === $grant && null === $clientId) {
            throw new InvalidArgumentException('The client credentials grant needs a client id; there is no user to identify the client instead.');
        }

        if (null !== $crossAppAccess && null === $clientId) {
            throw new InvalidArgumentException('Cross-app access needs the client id the authorization server knows this client by; it is what the authorization grant is bound to.');
        }

        if (null !== $privateKeyPem && null === $clientId) {
            throw new InvalidArgumentException('Private key JWT client authentication needs a client id to name in the assertion.');
        }
    }
}
