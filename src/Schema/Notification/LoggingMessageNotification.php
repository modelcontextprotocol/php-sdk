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
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Notification;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
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

        $level = LoggingLevel::from($params['level']);
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
