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
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Notification;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Log to stderr (stdio) or use OpenTelemetry instead.
 */
class LoggingMessageNotification extends Notification
{
    /**
     * @param LoggingLevel $level  the severity of this log message
     * @param mixed        $data   The data to be logged, such as a string message or an object. Any JSON serializable type is allowed here.
     * @param ?string      $logger an optional name of the logger issuing this message
     */
    public function __construct(
        public readonly LoggingLevel $level,
        public readonly mixed $data,
        public readonly ?string $logger = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'notifications/message';
    }

    protected static function fromParams(?array $params): Notification
    {
        if (!isset($params['level']) || !\is_string($params['level'])) {
            throw new InvalidArgumentException('Missing or invalid "level" parameter for "notifications/message" notification.');
        }

        if (!isset($params['data'])) {
            throw new InvalidArgumentException('Missing "data" parameter for "notifications/message" notification.');
        }

        if (null === $level = LoggingLevel::tryFrom($params['level'])) {
            throw new InvalidArgumentException(\sprintf('Invalid "level" parameter "%s" for "notifications/message" notification.', $params['level']));
        }

        if (isset($params['logger']) && !\is_string($params['logger'])) {
            throw new InvalidArgumentException('Invalid "logger" parameter for "notifications/message" notification.');
        }

        $data = \is_string($params['data']) ? $params['data'] : json_encode($params['data']);

        return new self($level, $data, $params['logger'] ?? null);
    }

    protected function getParams(): ?array
    {
        $params = [
            'level' => $this->level->value,
            'data' => $this->data,
        ];

        if (null !== $this->logger) {
            $params['logger'] = $this->logger;
        }

        return $params;
    }
}
