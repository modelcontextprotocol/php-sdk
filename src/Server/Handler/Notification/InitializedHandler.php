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
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Server\Session\SessionInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializedHandler implements NotificationHandlerInterface
{
    public function supports(Notification $notification): bool
    {
        return $notification instanceof InitializedNotification;
    }

    public function handle(Notification $message, SessionInterface $session): void
    {
        \assert($message instanceof InitializedNotification);

        $session->set('initialized', true);
    }
}
