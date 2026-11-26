<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Extension;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Tests\Unit\Server\Extension\ThingSeenNotification;

/**
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
final class ThingSeenHandler implements NotificationHandlerInterface
{
    public function supports(Notification $notification): bool
    {
        return $notification instanceof ThingSeenNotification;
    }

    public function handle(Notification $notification): void
    {
    }
}
