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
     * @param ClientMetadata $clientMetadata  what the client says about itself when registering
     * @param string         $redirectUri     where the authorization server sends the user back
     * @param ?string        $clientId        a client id issued out of band, skipping registration
     * @param ?string        $clientSecret    the matching secret, when there is one
     * @param string[]       $scopes          scopes to request regardless of what is advertised
     * @param bool           $offlineAccess   whether to ask for a refresh token where the server offers one
     * @param bool           $legacyDiscovery whether to fall back to pre-2025-06-18 discovery when a
     *                                        server publishes no protected resource metadata
     * @param ?string        $expectedIssuer  the only authorization server to authorize with
     *
     * @throws InvalidArgumentException when the redirect URI is insecure, or a client id is set without an expected issuer
     */
    public function __construct(
        public readonly ClientMetadata $clientMetadata,
        public readonly string $redirectUri = OAuth::DEFAULT_REDIRECT_URI,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly array $scopes = [],
        public readonly bool $offlineAccess = true,
        public readonly bool $legacyDiscovery = false,
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

        if (null !== $clientId && null === $expectedIssuer) {
            throw new InvalidArgumentException('A configured client needs an expected issuer, or a hostile MCP server could receive its authorization codes and secret. Call OAuth::setExpectedIssuer().');
        }
    }
}
