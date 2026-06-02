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
 * A short-lived, single-use authorization code (RFC 6749 Section 4.1) bound to
 * the client, redirect URI, scope, PKCE challenge and resource owner.
 */
final class AuthorizationCode
{
    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $userClaims
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly string $userId,
        public readonly array $userClaims,
        public readonly ?string $resource,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
