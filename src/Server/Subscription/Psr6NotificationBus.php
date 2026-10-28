<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Subscription;

use Mcp\Exception\InvalidArgumentException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Notification;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A notification bus over any PSR-6 cache pool, so publishers and listen streams
 * in different processes can see each other.
 *
 * It works like {@see Psr16NotificationBus}, with the same keys and the same
 * trade-off: two publishers can take the same sequence number, and one of their
 * entries is then lost.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
final class Psr6NotificationBus implements NotificationBusInterface
{
    private const CURSOR_KEY = 'cursor';

    private readonly MessageFactory $messageFactory;

    /**
     * @param string $prefix  namespace for this bus's keys, so one pool can carry several
     * @param int    $ttl     how long an entry stays readable, in seconds
     * @param int    $backlog how many entries a reader will look back over
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly string $prefix = 'mcp.notifications.',
        private readonly int $ttl = 120,
        private readonly int $backlog = 256,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?MessageFactory $messageFactory = null,
    ) {
        if ($this->backlog < 1) {
            throw new InvalidArgumentException(\sprintf('The notification backlog must be at least one entry, got %d.', $this->backlog));
        }

        $this->messageFactory = $messageFactory ?? MessageFactory::make();
    }

    public function publish(Notification $notification): void
    {
        $cursor = $this->cache->getItem($this->key(self::CURSOR_KEY));
        $sequence = \is_int($sequence = $cursor->get()) ? $sequence : 0;
        $entry = $this->cache->getItem($this->key((string) $sequence));

        // The entry goes first, so that no reader sees the cursor move past a missing entry
        $this->cache->saveDeferred($entry->set(json_encode($notification, \JSON_THROW_ON_ERROR))->expiresAfter($this->ttl));
        // No TTL, so that the cursors of subscribers stay valid when the bus goes quiet
        $this->cache->saveDeferred($cursor->set($sequence + 1)->expiresAfter(null));
        $this->cache->commit();
    }

    public function cursor(): int
    {
        $cursor = $this->cache->getItem($this->key(self::CURSOR_KEY))->get();

        return \is_int($cursor) ? $cursor : 0;
    }

    public function since(int $cursor): array
    {
        $head = $this->cursor();

        if ($cursor > $head) {
            // The counter was lost, evicted or cleared: whatever is there is new to this reader
            $cursor = 0;
        }

        $from = max($cursor, $head - $this->backlog);

        $keys = [];
        for ($sequence = $from; $sequence < $head; ++$sequence) {
            $keys[$sequence] = $this->key((string) $sequence);
        }

        // getItems() may not yield in the order of the keys
        $raws = [];
        foreach ($this->cache->getItems($keys) as $key => $item) {
            $raws[$key] = $item->get();
        }

        $found = [];

        foreach ($keys as $sequence => $key) {
            $raw = $raws[$key] ?? null;

            if (!\is_string($raw)) {
                continue;
            }

            try {
                foreach ($this->messageFactory->create($raw) as $message) {
                    if ($message instanceof Notification) {
                        $found[] = $message;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Dropped an unreadable notification from the bus.', ['sequence' => $sequence, 'exception' => $e]);
            }
        }

        return [$found, $head];
    }

    private function key(string $suffix): string
    {
        return $this->prefix.$suffix;
    }
}
