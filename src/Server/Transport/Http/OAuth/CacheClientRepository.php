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
 * PSR-16 backed {@see ClientRepositoryInterface}. Clients are stored without an
 * expiry. Suitable for examples and small deployments; back DCR with durable
 * storage for production.
 */
final class CacheClientRepository implements ClientRepositoryInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp_oauth_client_',
    ) {
    }

    public function find(string $clientId): ?Client
    {
        $stored = $this->cache->get($this->prefix.$clientId);

        return $stored instanceof Client ? $stored : null;
    }

    public function save(Client $client): void
    {
        $this->cache->set($this->prefix.$client->clientId, $client);
    }
}
