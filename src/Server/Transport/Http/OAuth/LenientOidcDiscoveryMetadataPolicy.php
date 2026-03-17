<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

/**
 * Lenient metadata policy for identity providers that do not yet include
 * code_challenge_methods_supported in their OIDC discovery response.
 *
 * While the MCP specification requires authorization servers to advertise
 * code_challenge_methods_supported, some providers (e.g. FusionAuth,
 * Microsoft Entra ID) omit this field despite supporting PKCE with S256.
 *
 * Use this policy as a pragmatic workaround until those providers update
 * their discovery metadata.
 */
final class LenientOidcDiscoveryMetadataPolicy implements OidcDiscoveryMetadataPolicyInterface
{
    public function isValid(mixed $metadata): bool
    {
        return \is_array($metadata)
            && isset($metadata['authorization_endpoint'], $metadata['token_endpoint'], $metadata['jwks_uri'])
            && \is_string($metadata['authorization_endpoint'])
            && '' !== trim($metadata['authorization_endpoint'])
            && \is_string($metadata['token_endpoint'])
            && '' !== trim($metadata['token_endpoint'])
            && \is_string($metadata['jwks_uri'])
            && '' !== trim($metadata['jwks_uri']);
    }
}
