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

use stdClass;
use Mcp\Exception\InvalidArgumentException;

/**
 * @phpstan-type RequestData array{
 *     jsonrpc: string,
 *     id: string|int,
 *     method: string,
 *     params?: array<string, mixed>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
abstract class Request implements HasMethodInterface, MessageInterface
{
    protected string|int $id;
    /**
     * @var array<string, mixed>|null
     */
    protected ?array $meta = null;

    abstract public static function getMethod(): string;

    /**
     * @param RequestData $data
     */
    public static function fromArray(array $data): static
    {
        if (($data['jsonrpc'] ?? null) !== MessageInterface::JSONRPC_VERSION) {
            throw new InvalidArgumentException('Invalid or missing "jsonrpc" version for Request.');
        }
        if (!isset($data['id']) || !\is_string($data['id']) && !\is_int($data['id'])) {
            throw new InvalidArgumentException('Invalid or missing "id" for Request.');
        }
        if (!isset($data['method']) || !\is_string($data['method'])) {
            throw new InvalidArgumentException('Invalid or missing "method" for Request.');
        }
        $params = $data['params'] ?? null;
        if ($params instanceof stdClass) {
            $params = (array) $params;
        }
        if (null !== $params && !\is_array($params)) {
            throw new InvalidArgumentException('"params" for Request must be an array/object or null.');
        }

        $request = static::fromParams($params);
        $request->id = $data['id'];

        if (isset($data['params']['_meta'])) {
            $request->meta = $data['params']['_meta'];
        }

        return $request;
    }

    /**
     * @param array<string, mixed>|null $params
     */
    abstract protected static function fromParams(?array $params): static;

    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * @return RequestData
     */
    public function jsonSerialize(): array
    {
        $array = [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $this->id,
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
     * @return array<non-empty-string, mixed>|null
     */
    abstract protected function getParams(): ?array;
}
