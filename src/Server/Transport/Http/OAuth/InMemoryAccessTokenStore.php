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
 * In-memory {@see AccessTokenStoreInterface} for tests, examples and
 * single-process servers.
 */
final class InMemoryAccessTokenStore implements AccessTokenStoreInterface
{
    /** @var array<string, bool> token id => revoked */
    private array $tokens = [];

    public function record(string $tokenId, string $clientId, string $userId, \DateTimeImmutable $expiresAt): void
    {
        $this->tokens[$tokenId] = false;
    }

    public function isRevoked(string $tokenId): bool
    {
        return $this->tokens[$tokenId] ?? false;
    }

    public function revoke(string $tokenId): void
    {
        $this->tokens[$tokenId] = true;
    }
}
