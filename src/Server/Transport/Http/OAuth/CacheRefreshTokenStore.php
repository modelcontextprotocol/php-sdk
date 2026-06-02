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

use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 backed {@see RefreshTokenStoreInterface} for multi-process deployments.
 * The cache TTL mirrors the token lifetime so expired tokens vanish.
 */
final class CacheRefreshTokenStore implements RefreshTokenStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp_oauth_refresh_',
    ) {
    }

    public function store(string $token, RefreshToken $refreshToken): void
    {
        $ttl = $refreshToken->expiresAt->getTimestamp() - time();
        if ($ttl <= 0) {
            return;
        }

        $this->cache->set($this->prefix.$token, $refreshToken, $ttl);
    }

    public function consume(string $token): ?RefreshToken
    {
        $key = $this->prefix.$token;
        $stored = $this->cache->get($key);
        $this->cache->delete($key);

        return $stored instanceof RefreshToken ? $stored : null;
    }
}
