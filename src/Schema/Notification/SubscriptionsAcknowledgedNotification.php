<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Notification;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\SubscriptionFilter;

/**
 * The first message of a `subscriptions/listen` stream, with the notification types the server agreed to honor.
 *
 * @author Diego D'amico
 */
final class SubscriptionsAcknowledgedNotification extends Notification
{
    public function __construct(
        public readonly SubscriptionFilter $notifications,
    ) {
    }

    public static function getMethod(): string
    {
        return 'notifications/subscriptions/acknowledged';
    }

    protected static function fromParams(?array $params): Notification
    {
        if (!\is_array($params['notifications'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid "notifications" parameter for notifications/subscriptions/acknowledged.');
        }

        return new self(SubscriptionFilter::fromArray($params['notifications']));
    }

    protected function getParams(): array
    {
        return [
            'notifications' => $this->notifications->jsonSerialize(),
        ];
    }
}
