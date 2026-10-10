<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Handler\Notification;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ProgressNotification;

/**
 * Internal handler for progress notifications.
 *
 * Hands progress on as soon as it is parsed, so it keeps its order among other notifications.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @internal
 */
class ProgressNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @param \Closure(ProgressNotification): void $deliver
     */
    public function __construct(
        private readonly \Closure $deliver,
    ) {
    }

    public function supports(Notification $notification): bool
    {
        return $notification instanceof ProgressNotification;
    }

    public function handle(Notification $notification): void
    {
        if (!$notification instanceof ProgressNotification) {
            return;
        }

        ($this->deliver)($notification);
    }
}
