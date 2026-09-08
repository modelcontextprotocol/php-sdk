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
 * How the client proves who it is at the token endpoint (RFC 7591 "token_endpoint_auth_method").
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum TokenEndpointAuthMethod: string
{
    /** Public client: no client authentication at all, PKCE carries the security. */
    case None = 'none';

    /** Client id and secret in the HTTP Basic authorization header (RFC 6749 section 2.3.1). */
    case ClientSecretBasic = 'client_secret_basic';

    /** Client id and secret in the request body. */
    case ClientSecretPost = 'client_secret_post';

    /** A JWT assertion signed with the client's private key (RFC 7523). */
    case PrivateKeyJwt = 'private_key_jwt';

    /**
     * Pick the method to use against a server advertising the given list.
     *
     * Preference runs from strongest to weakest, but a client without a secret can only
     * ever be public, and one that was handed a secret should use it. An authorization
     * server that advertises nothing is treated as RFC 6749 does: Basic is the default.
     *
     * @param string[] $supported the server's token_endpoint_auth_methods_supported
     */
    public static function negotiate(array $supported, bool $hasSecret, bool $hasPrivateKey = false): self
    {
        $candidates = $hasPrivateKey
            ? [self::PrivateKeyJwt, self::ClientSecretBasic, self::ClientSecretPost, self::None]
            : ($hasSecret
                ? [self::ClientSecretBasic, self::ClientSecretPost, self::None]
                : [self::None]);

        if ([] === $supported) {
            return $candidates[0];
        }

        foreach ($candidates as $candidate) {
            if (\in_array($candidate->value, $supported, true)) {
                return $candidate;
            }
        }

        // Nothing the client can satisfy was advertised. Sending the strongest thing it
        // has is more useful than refusing outright: servers under-report this field.
        return $candidates[0];
    }
}
