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

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\MessageFactory;
use Mcp\Server\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;

/**
 * A notification bus over any PSR-16 cache, so publishers and listen streams in
 * different processes can see each other.
 *
 * The sequence counter and the entries are separate keys: a publisher bumps the
 * counter and writes its entry under the number it got. That is not atomic
 * across processes — two publishers can take the same number and one entry is
 * lost — which is the trade every PSR-16-only design makes, and acceptable
 * here: a lost `tools/list_changed` costs a client one stale list until the TTL
 * lapses, not correctness. A backend with atomic increments (Redis `INCR`)
 * should implement {@see NotificationBusInterface} directly.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Psr16NotificationBus implements NotificationBusInterface
{
    private const CURSOR_KEY = 'cursor';

    private readonly MessageFactory $messageFactory;

    /**
     * @param string $prefix  namespace for this bus's keys, so one cache can carry several
     * @param int    $ttl     how long an entry stays readable, in seconds
     * @param int    $backlog how many entries a reader will look back over
     */
    public function __construct(
        private readonly CacheInterface $cache,
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
        $sequence = $this->cursor();

        $this->cache->set($this->key((string) $sequence), json_encode($notification, \JSON_THROW_ON_ERROR), $this->ttl);
        $this->cache->set($this->key(self::CURSOR_KEY), $sequence + 1, $this->ttl);
    }

    public function cursor(): int
    {
        $cursor = $this->cache->get($this->key(self::CURSOR_KEY), 0);

        return \is_int($cursor) ? $cursor : 0;
    }

    public function since(int $cursor): array
    {
        $head = $this->cursor();

        // A reader that fell far behind reads what is still there, not
        // everything it missed.
        $from = max($cursor, $head - $this->backlog);

        $found = [];

        for ($sequence = $from; $sequence < $head; ++$sequence) {
            $raw = $this->cache->get($this->key((string) $sequence));

            if (!\is_string($raw)) {
                // Expired, or lost to a concurrent publisher taking the same
                // number. Neither is worth failing the stream over.
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
