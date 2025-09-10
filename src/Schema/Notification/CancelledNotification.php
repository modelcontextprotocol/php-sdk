<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\Notification;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Notification;

/**
 * This notification can be sent by either side to indicate that it is cancelling a previously-issued request.
 *
 * The request SHOULD still be in-flight, but due to communication latency, it is always possible that this notification MAY arrive after the request has already finished.
 *
 * This notification indicates that the result will be unused, so any associated processing SHOULD cease.
 *
 * A client MUST NOT attempt to cancel its `initialize` request.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class CancelledNotification extends Notification
{
    /**
     * @param string|int $requestId The ID of the request that is being cancelled. This MUST correspond to the ID of a request previously issued in the same direction.
     * @param ?string    $reason    An optional string describing the reason for the cancellation. This MAY be logged or presented to the user.
     */
    public function __construct(
        public readonly string|int $requestId,
        public readonly ?string $reason = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'notifications/cancelled';
    }

    protected static function fromParams(?array $params): Notification
    {
        if (null === $params || !isset($params['requestId']) || (!\is_string($params['requestId']) && !\is_int($params['requestId']))) {
            throw new InvalidArgumentException('Invalid or missing "requestId" parameter for "notifications/cancelled" notification.');
        }

        return new self($params['requestId'], $params['reason'] ?? null);
    }

    protected function getParams(): ?array
    {
        $params = ['requestId' => $this->requestId];

        if (null !== $this->reason) {
            $params['reason'] = $this->reason;
        }

        return $params;
    }
}
