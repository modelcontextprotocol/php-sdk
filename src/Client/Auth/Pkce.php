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
 * A Proof Key for Code Exchange pair (RFC 7636), always with S256.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Pkce
{
    public const METHOD = 'S256';

    private function __construct(
        public readonly string $verifier,
        public readonly string $challenge,
    ) {
    }

    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(32));

        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    public static function fromVerifier(string $verifier): self
    {
        return new self($verifier, self::base64Url(hash('sha256', $verifier, true)));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
