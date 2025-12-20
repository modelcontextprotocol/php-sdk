<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Handler;

use Mcp\Handler\NotificationHandlerInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ProgressNotification;

/**
 * Handler for progress notifications from the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ProgressNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @param callable(ProgressNotification): void $callback
     */
    public function __construct(
        private readonly mixed $callback,
    ) {
    }

    public function supports(Notification $notification): bool
    {
        return $notification instanceof ProgressNotification;
    }

    public function handle(Notification $notification): void
    {
        ($this->callback)($notification);
    }
}
