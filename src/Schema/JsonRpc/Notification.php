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

namespace Mcp\Schema\JsonRpc;

use Mcp\Exception\InvalidArgumentException;

/**
 * @phpstan-type NotificationData array{
 *     jsonrpc: string,
 *     method: string,
 *     params?: array<string, mixed>|null
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class Notification implements HasMethodInterface, MessageInterface
{
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $meta = null;

    abstract public static function getMethod(): string;

    /**
     * @param NotificationData $data
     */
    public static function fromArray(array $data): self
    {
        if (isset($data['id'])) {
            throw new InvalidArgumentException('Notification MUST NOT contain an "id" field.');
        }
        if (!isset($data['method']) || !\is_string($data['method'])) {
            throw new InvalidArgumentException('Invalid or missing "method" for Notification.');
        }
        $params = $data['params'] ?? null;
        if (null !== $params && !\is_array($params)) {
            throw new InvalidArgumentException('"params" for Notification must be an array/object or null.');
        }

        $notification = static::fromParams($params);

        if (isset($data['params']['_meta'])) {
            $notification->meta = $data['params']['_meta'];
        }

        return $notification;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    abstract protected static function fromParams(?array $params): self;

    /**
     * @return NotificationData
     */
    public function jsonSerialize(): array
    {
        $array = [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'method' => static::getMethod(),
        ];
        if (null !== $params = $this->getParams()) {
            $array['params'] = $params;
        }

        if (null !== $this->meta && !isset($params['meta'])) {
            $array['params']['_meta'] = $this->meta;
        }

        return $array;
    }

    /**
     * @return array<string, mixed>|null
     */
    abstract protected function getParams(): ?array;
}
