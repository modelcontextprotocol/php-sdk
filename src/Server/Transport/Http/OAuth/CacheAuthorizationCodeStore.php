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
 * PSR-16 backed {@see AuthorizationCodeStoreInterface} for multi-process
 * deployments. The cache TTL mirrors the code lifetime so expired codes vanish.
 */
final class CacheAuthorizationCodeStore implements AuthorizationCodeStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp_oauth_code_',
    ) {
    }

    public function store(string $code, AuthorizationCode $authorizationCode): void
    {
        $ttl = $authorizationCode->expiresAt->getTimestamp() - time();
        if ($ttl <= 0) {
            return;
        }

        $this->cache->set($this->prefix.$code, $authorizationCode, $ttl);
    }

    public function consume(string $code): ?AuthorizationCode
    {
        $key = $this->prefix.$code;
        $stored = $this->cache->get($key);
        $this->cache->delete($key);

        return $stored instanceof AuthorizationCode ? $stored : null;
    }
}
