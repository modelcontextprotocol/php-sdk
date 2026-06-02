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
 * A refresh token grant record (RFC 6749 Section 6), bound to a client and
 * resource owner. Refresh tokens are rotated on every use.
 */
final class RefreshToken
{
    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $userClaims
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
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
