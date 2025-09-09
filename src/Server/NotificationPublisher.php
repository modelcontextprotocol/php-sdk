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

namespace Mcp\Server;

use Mcp\Schema\JsonRpc\Notification;

/**
 * @author Aggelos Bellos <aggelosbellos7@gmail.com>
 */
class NotificationPublisher
{
    /** @var list<Notification> */
    private array $queue = [];

    public function enqueue(Notification $notification): void
    {
        $this->queue[] = $notification;
    }

    /**
     * @return \Generator<int, Notification, void, void>
     */
    public function flush(): iterable
    {
        $out = $this->queue;
        $this->queue = [];

        yield from $out;
    }
}
