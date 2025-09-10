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
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceUpdatedNotification extends Notification
{
    public function __construct(
        public readonly string $uri,
    ) {
    }

    public static function getMethod(): string
    {
        return 'notifications/resources/updated';
    }

    protected static function fromParams(?array $params): Notification
    {
        if (null === $params || !isset($params['uri']) || !\is_string($params['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" parameter for notifications/resources/updated notification.');
        }

        return new self($params['uri']);
    }

    protected function getParams(): ?array
    {
        return [
            'uri' => $this->uri,
        ];
    }
}
