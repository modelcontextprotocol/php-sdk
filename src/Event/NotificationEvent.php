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

namespace Mcp\Event;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Session\SessionInterface;

/**
 * Event dispatched when any notification is received from the client.
 *
 * Listeners can modify the notification before it's processed by handlers.
 *
 * @author Edouard Courty <edouard.courty2@gmail.com>
 */
final class NotificationEvent
{
    public function __construct(
        private Notification $notification,
        private readonly SessionInterface $session,
    ) {
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function setNotification(Notification $notification): void
    {
        $this->notification = $notification;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    public function getMethod(): string
    {
        return $this->notification::getMethod();
    }
}
