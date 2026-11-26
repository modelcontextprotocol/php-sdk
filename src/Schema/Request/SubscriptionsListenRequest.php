<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Request;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\SubscriptionFilter;

/**
 * Opens a long-lived stream for the notifications the client opts in to (2026-07-28).
 *
 * @author Diego D'amico
 */
final class SubscriptionsListenRequest extends Request
{
    public function __construct(
        public readonly SubscriptionFilter $notifications,
    ) {
    }

    public static function getMethod(): string
    {
        return 'subscriptions/listen';
    }

    protected static function fromParams(?array $params): static
    {
        if (!\is_array($params['notifications'] ?? null)) {
            throw new InvalidArgumentException('Missing or invalid "notifications" parameter for subscriptions/listen.');
        }

        return new self(SubscriptionFilter::fromArray($params['notifications']));
    }

    /**
     * @return array{notifications: array<string, mixed>|\stdClass}
     */
    protected function getParams(): array
    {
        return [
            'notifications' => $this->notifications->jsonSerialize(),
        ];
    }
}
