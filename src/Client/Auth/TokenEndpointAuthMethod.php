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
     * Pick the method for the advertised list: no list means Basic (RFC 8414), and a client without a secret or key is public.
     *
     * @param string[] $supported   the server's token_endpoint_auth_methods_supported
     * @param bool     $mayBePublic false for a grant that requires client authentication, like client credentials
     */
    public static function negotiate(array $supported, bool $hasSecret, bool $hasPrivateKey = false, bool $mayBePublic = true): self
    {
        $candidates = [
            ...($hasPrivateKey ? [self::PrivateKeyJwt] : []),
            ...($hasSecret ? [self::ClientSecretBasic, self::ClientSecretPost] : []),
        ];

        if ($mayBePublic || [] === $candidates) {
            $candidates[] = self::None;
        }

        if ([] === $supported) {
            return $hasSecret ? self::ClientSecretBasic : $candidates[0];
        }

        foreach ($candidates as $candidate) {
            if (\in_array($candidate->value, $supported, true)) {
                return $candidate;
            }
        }

        // Servers under-report this field, so the strongest method is still worth trying.
        return $candidates[0];
    }
}
