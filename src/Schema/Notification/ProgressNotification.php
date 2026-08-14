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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Notification;

/**
 * An out-of-band notification used to inform the receiver of a progress update for a long-running request.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ProgressNotification extends Notification
{
    /**
     * @param string|int $progressToken the progress token which was given in the initial request, used to
     *                                  associate this notification with the request that is proceeding
     * @param float      $progress      The progress thus far. This should increase every time progress is
     *                                  made, even if the total is unknown.
     * @param ?float     $total         total number of items to process (or total progress required), if known
     * @param ?string    $message       an optional message describing the current progress
     */
    public function __construct(
        public readonly string|int $progressToken,
        public readonly float $progress,
        public readonly ?float $total = null,
        public readonly ?string $message = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'notifications/progress';
    }

    protected static function fromParams(?array $params): Notification
    {
        // JSON numbers decode to int when they have no fractional part.
        if (!isset($params['progressToken']) || !\is_string($params['progressToken']) && !\is_int($params['progressToken'])) {
            throw new InvalidArgumentException('Missing or invalid "progressToken" parameter for "notifications/progress" notification.');
        }

        if (!isset($params['progress']) || !\is_float($params['progress']) && !\is_int($params['progress'])) {
            throw new InvalidArgumentException('Missing or invalid "progress" parameter for "notifications/progress" notification.');
        }

        if (isset($params['total']) && !\is_float($params['total']) && !\is_int($params['total'])) {
            throw new InvalidArgumentException('Invalid "total" parameter for "notifications/progress" notification.');
        }

        if (isset($params['message']) && !\is_string($params['message'])) {
            throw new InvalidArgumentException('Invalid "message" parameter for "notifications/progress" notification.');
        }

        return new self(
            $params['progressToken'],
            (float) $params['progress'],
            isset($params['total']) ? (float) $params['total'] : null,
            $params['message'] ?? null,
        );
    }

    protected function getParams(): ?array
    {
        $params = [
            'progressToken' => $this->progressToken,
            'progress' => $this->progress,
        ];

        if (null !== $this->total) {
            $params['total'] = $this->total;
        }

        if (null !== $this->message) {
            $params['message'] = $this->message;
        }

        return $params;
    }
}
