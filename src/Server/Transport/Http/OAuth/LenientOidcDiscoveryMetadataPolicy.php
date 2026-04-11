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
 * Lenient metadata policy for identity providers that omit
 * code_challenge_methods_supported from their OIDC discovery response
 * despite supporting PKCE (e.g. FusionAuth, Microsoft Entra ID).
 *
 * If code_challenge_methods_supported is present, it is still validated.
 * If absent, the downstream OAuthProxyMiddleware defaults to ['S256'].
 *
 * @author Simon Chrzanowski <simon.chrzanowski@quentic.com>
 */
final class LenientOidcDiscoveryMetadataPolicy implements OidcDiscoveryMetadataPolicyInterface
{
    public function isValid(mixed $metadata): bool
    {
        if (!\is_array($metadata)
            || !isset($metadata['authorization_endpoint'], $metadata['token_endpoint'], $metadata['jwks_uri'])
            || !\is_string($metadata['authorization_endpoint'])
            || '' === trim($metadata['authorization_endpoint'])
            || !\is_string($metadata['token_endpoint'])
            || '' === trim($metadata['token_endpoint'])
            || !\is_string($metadata['jwks_uri'])
            || '' === trim($metadata['jwks_uri'])
        ) {
            return false;
        }

        if (\array_key_exists('code_challenge_methods_supported', $metadata)) {
            if (!\is_array($metadata['code_challenge_methods_supported']) || [] === $metadata['code_challenge_methods_supported']) {
                return false;
            }

            foreach ($metadata['code_challenge_methods_supported'] as $method) {
                if (!\is_string($method) || '' === trim($method)) {
                    return false;
                }
            }
        }

        return true;
    }
}
