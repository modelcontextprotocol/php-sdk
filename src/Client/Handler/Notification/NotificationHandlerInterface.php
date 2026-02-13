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

/**
 * Interface for handling notifications from the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface NotificationHandlerInterface
{
    /**
     * Check if this handler supports the given notification.
     */
    public function supports(Notification $notification): bool;

    /**
     * Handle the notification.
     */
    public function handle(Notification $notification): void;
}
