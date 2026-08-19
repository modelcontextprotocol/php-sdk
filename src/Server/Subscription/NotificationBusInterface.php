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

/**
 * Carries server-initiated notifications to whichever `subscriptions/listen`
 * streams are open.
 *
 * A bus and not a registry of listeners, because the two ends are rarely in the
 * same process: under PHP-FPM the request that changes the tool list and the
 * request holding a listen stream open are different workers, so the only thing
 * they can share is storage. The read side is a cursor rather than a
 * subscription: a stream that opens at cursor `c` reads forward from `c`, and
 * nothing has to know a stream exists before it does.
 *
 * Implementations are expected to drop old entries — a listen stream that goes
 * away must not make the backlog grow without bound.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface NotificationBusInterface
{
    /**
     * Publishes a notification to every stream currently reading forward.
     */
    public function publish(Notification $notification): void;

    /**
     * The cursor a stream opening now should start from.
     *
     * Deliberately "now" and not "the beginning": a client that subscribes
     * wants what happens next, not a replay of everything the server has done.
     */
    public function cursor(): int;

    /**
     * Notifications published after $cursor, and the cursor to read from next.
     *
     * @return array{list<Notification>, int}
     */
    public function since(int $cursor): array;
}
