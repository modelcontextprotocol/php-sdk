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
 * In-memory {@see RefreshTokenStoreInterface}. Consuming a token deletes it,
 * enforcing rotation. Expiry is enforced by the granter.
 */
final class InMemoryRefreshTokenStore implements RefreshTokenStoreInterface
{
    /** @var array<string, RefreshToken> */
    private array $tokens = [];

    public function store(string $token, RefreshToken $refreshToken): void
    {
        $this->tokens[$token] = $refreshToken;
    }

    public function consume(string $token): ?RefreshToken
    {
        $stored = $this->tokens[$token] ?? null;
        unset($this->tokens[$token]);

        return $stored;
    }
}
