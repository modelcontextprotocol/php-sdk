<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Notification;

use Mcp\Schema\JsonRpc\Notification;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface NotificationHandlerInterface
{
    public function supports(Notification $notification): bool;

    public function handle(Notification $notification, SessionInterface $session): void;
}
