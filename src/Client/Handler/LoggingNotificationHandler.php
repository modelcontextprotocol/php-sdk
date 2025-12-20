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
use Mcp\Schema\Notification\LoggingMessageNotification;

/**
 * Handler for logging message notifications from the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class LoggingNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @param callable(LoggingMessageNotification): void $callback
     */
    public function __construct(
        private readonly mixed $callback,
    ) {
    }

    public function supports(Notification $notification): bool
    {
        return $notification instanceof LoggingMessageNotification;
    }

    public function handle(Notification $notification): void
    {
        ($this->callback)($notification);
    }
}
