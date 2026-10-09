<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Suspension;

use Mcp\Schema\JsonRpc\Notification;

/**
 * Payload a handler fiber suspends with to send a notification to the client.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class NotificationSuspension
{
    public function __construct(
        public readonly Notification $notification,
        public readonly string $sessionId,
    ) {
    }
}
