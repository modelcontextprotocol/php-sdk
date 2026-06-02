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
 * Proof Key for Code Exchange helpers (RFC 7636). Only the S256 method is
 * supported, as required by OAuth 2.1.
 *
 * @internal
 */
final class Pkce
{
    public const METHOD_S256 = 'S256';

    /**
     * Computes the S256 code challenge for a verifier.
     */
    public static function challenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * Constant-time verification of a code verifier against a stored challenge.
     */
    public static function verify(string $codeVerifier, string $codeChallenge): bool
    {
        return hash_equals($codeChallenge, self::challenge($codeVerifier));
    }
}
