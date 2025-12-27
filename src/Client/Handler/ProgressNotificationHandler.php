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

use Mcp\Client\Session\ClientSessionInterface;
use Mcp\Handler\NotificationHandlerInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\ProgressNotification;

/**
 * Internal handlerc for progress notifications.
 *
 * Writes progress data to session for transport to consume and execute callbacks.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @internal
 */
class ProgressNotificationHandler implements NotificationHandlerInterface
{
    public function __construct(
        private readonly ClientSessionInterface $session,
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

        $this->session->storeProgress(
            (string) $notification->progressToken,
            $notification->progress,
            $notification->total,
            $notification->message,
        );
    }
}
