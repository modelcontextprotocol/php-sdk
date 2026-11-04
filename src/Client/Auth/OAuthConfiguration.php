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
 * Everything about how this client wants to authorize, decided before the first request.
 *
 * Built by {@see OAuth}; nothing here is discovered at runtime, which is what keeps the
 * discovered half -- issuers, endpoints, scopes, tokens -- clearly separate from the
 * half the application chose.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuthConfiguration
{
    /**
     * @param ClientMetadata $clientMetadata    what the client says about itself when registering
     * @param string         $redirectUri       where the authorization server sends the user back
     * @param ?string        $clientId          a client id issued out of band, skipping registration
     * @param ?string        $clientSecret      the matching secret, when there is one
     * @param ?string        $clientMetadataUrl a URL serving the client metadata document, usable as a
     *                                          client id against servers that accept one
     * @param string[]       $scopes            scopes to request regardless of what is advertised
     * @param bool           $offlineAccess     whether to ask for a refresh token where the server offers one
     * @param bool           $legacyDiscovery   whether to fall back to pre-2025-06-18 discovery when a
     *                                          server publishes no protected resource metadata
     * @param ?string        $resource          the canonical resource URI to request tokens for, when it
     *                                          should not be derived from the endpoint being called
     */
    public function __construct(
        public readonly ClientMetadata $clientMetadata,
        public readonly string $redirectUri = OAuth::DEFAULT_REDIRECT_URI,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $clientMetadataUrl = null,
        public readonly array $scopes = [],
        public readonly bool $offlineAccess = true,
        public readonly bool $legacyDiscovery = false,
        public readonly ?string $resource = null,
    ) {
    }
}
