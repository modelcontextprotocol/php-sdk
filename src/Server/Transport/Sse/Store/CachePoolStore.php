<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Sse\Store;

use Mcp\Server\Transport\Sse\StoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CachePoolStore implements StoreInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
    ) {
    }

    public function push(Uuid $id, string $message): void
    {
        $item = $this->cachePool->getItem($this->getCacheKey($id));

        $messages = $item->isHit() ? $item->get() : [];
        $messages[] = $message;
        $item->set($messages);

        $this->cachePool->save($item);
    }

    public function pop(Uuid $id): ?string
    {
        $item = $this->cachePool->getItem($this->getCacheKey($id));

        if (!$item->isHit()) {
            return null;
        }

        $messages = $item->get();

        if ([] === $messages) {
            return null;
        }

        $message = array_shift($messages);

        $item->set($messages);
        $this->cachePool->save($item);

        return $message;
    }

    public function remove(Uuid $id): void
    {
        $this->cachePool->deleteItem($this->getCacheKey($id));
    }

    private function getCacheKey(Uuid $id): string
    {
        return 'message_'.$id->toRfc4122();
    }
}
