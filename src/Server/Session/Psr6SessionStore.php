<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Session store over any PSR-6 cache pool.
 *
 * Each session is stored under the prefix followed by the session id, the same
 * key as {@see Psr16SessionStore}.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class Psr6SessionStore implements SessionStoreInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $prefix = 'mcp-',
        private readonly int $ttl = 3600,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return $this->cache->hasItem($this->getKey($id));
    }

    public function read(Uuid $id): string|false
    {
        return $this->cache->getItem($this->getKey($id))->get() ?? false;
    }

    public function write(Uuid $id, string $data): bool
    {
        $item = $this->cache->getItem($this->getKey($id));

        return $this->cache->save($item->set($data)->expiresAfter($this->ttl));
    }

    public function destroy(Uuid $id): bool
    {
        return $this->cache->deleteItem($this->getKey($id));
    }

    public function gc(): array
    {
        return [];
    }

    private function getKey(Uuid $id): string
    {
        return $this->prefix.$id;
    }
}
