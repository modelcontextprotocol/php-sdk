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
use Mcp\Schema\Notification\LoggingMessageNotification;

/**
 * Handler for logging message notifications from the server.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Log to stderr (stdio) or use OpenTelemetry instead.
 */
class LoggingNotificationHandler implements NotificationHandlerInterface
{
    /**
     * @param callable(LoggingMessageNotification): void $callback
     */
    public function __construct(
        private readonly mixed $callback,
    ) {
        trigger_deprecation('mcp/sdk', '0.8', 'MCP logging is deprecated since protocol revision 2026-07-28 (SEP-2577); log to stderr or use OpenTelemetry instead.');
    }

    public function supports(Notification $notification): bool
    {
        return $notification instanceof LoggingMessageNotification;
    }

    public function handle(Notification $notification): void
    {
        \assert($notification instanceof LoggingMessageNotification);

        ($this->callback)($notification);
    }
}
