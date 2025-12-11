<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Uuid;

/**
 * PSR-16 compliant cache-based session store.
 *
 * This implementation uses any PSR-16 compliant cache as the storage backend
 * for session data. Each session is stored with a prefixed key using the session ID.
 *
 * @author luoyue <1569097443@qq.com>
 */
class Psr16StoreSession implements SessionStoreInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp-',
        private readonly int $ttl = 3600,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        try {
            return $this->cache->has($this->getKey($id));
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(Uuid $id): string|false
    {
        try {
            return $this->cache->get($this->getKey($id), false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(Uuid $id, string $data): bool
    {
        try {
            return $this->cache->set($this->getKey($id), $data, $this->ttl);
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(Uuid $id): bool
    {
        try {
            return $this->cache->delete($this->getKey($id));
        } catch (\Throwable) {
            return false;
        }
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
