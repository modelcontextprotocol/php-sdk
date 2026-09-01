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
use Mcp\Server\Exception\InvalidArgumentException;

/**
 * A notification bus held in one process's memory.
 *
 * Correct for stdio and for persistent runtimes where the whole server lives in
 * one process (Swoole, FrankenPHP worker mode, ReactPHP). Under PHP-FPM it is
 * not: the worker that publishes and the worker holding the stream open are
 * different processes, and a notification published in one is invisible to the
 * other. {@see Psr16NotificationBus} is the answer there.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InMemoryNotificationBus implements NotificationBusInterface
{
    /** @var array<int, Notification> */
    private array $entries = [];

    private int $next = 0;

    /**
     * @param int $backlog how many notifications to keep before dropping the oldest
     */
    public function __construct(
        private readonly int $backlog = 256,
    ) {
        if ($this->backlog < 1) {
            throw new InvalidArgumentException(\sprintf('The notification backlog must be at least one entry, got %d.', $this->backlog));
        }
    }

    public function publish(Notification $notification): void
    {
        $this->entries[$this->next] = $notification;
        ++$this->next;

        // A stream that went away must not make this grow forever.
        if (\count($this->entries) > $this->backlog) {
            $this->entries = \array_slice($this->entries, -$this->backlog, preserve_keys: true);
        }
    }

    public function cursor(): int
    {
        return $this->next;
    }

    public function since(int $cursor): array
    {
        $found = [];

        foreach ($this->entries as $sequence => $notification) {
            if ($sequence >= $cursor) {
                $found[] = $notification;
            }
        }

        return [$found, $this->next];
    }
}
