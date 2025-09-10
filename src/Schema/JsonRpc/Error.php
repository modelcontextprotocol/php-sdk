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
 * A response to a request that indicates an error occurred.
 *
 * @phpstan-type ErrorData array{
 *     jsonrpc: string,
 *     id: string|int,
 *     code: int,
 *     message: string,
 *     data?: mixed,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Error implements MessageInterface
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const SERVER_ERROR = -32000;
    public const RESOURCE_NOT_FOUND = -32002;

    /**
     * @param int        $code    the error type that occurred
     * @param string     $message a short description of the error
     * @param mixed|null $data    additional information about the error
     */
    public function __construct(
        public readonly string|int $id,
        public readonly int $code,
        public readonly string $message,
        public readonly mixed $data = null,
    ) {
    }

    /**
     * @param ErrorData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['jsonrpc']) || MessageInterface::JSONRPC_VERSION !== $data['jsonrpc']) {
            throw new InvalidArgumentException('Invalid or missing "jsonrpc" in Error data.');
        }
        if (!isset($data['id']) || !\is_string($data['id'])) {
            throw new InvalidArgumentException('Invalid or missing "id" in Error data.');
        }
        if (!isset($data['code']) || !\is_int($data['code'])) {
            throw new InvalidArgumentException('Invalid or missing "code" in Error data.');
        }
        if (!isset($data['message']) || !\is_string($data['message'])) {
            throw new InvalidArgumentException('Invalid or missing "message" in Error data.');
        }

        return new self($data['id'], $data['code'], $data['message'], $data['data'] ?? null);
    }

    public static function forParseError(string $message, string|int $id = ''): self
    {
        return new self($id, self::PARSE_ERROR, $message);
    }

    public static function forInvalidRequest(string $message, string|int $id = ''): self
    {
        return new self($id, self::INVALID_REQUEST, $message);
    }

    public static function forMethodNotFound(string $message, string|int $id = ''): self
    {
        return new self($id, self::METHOD_NOT_FOUND, $message);
    }

    public static function forInvalidParams(string $message, string|int $id = ''): self
    {
        return new self($id, self::INVALID_PARAMS, $message);
    }

    public static function forInternalError(string $message, string|int $id = ''): self
    {
        return new self($id, self::INTERNAL_ERROR, $message);
    }

    public static function forServerError(string $message, string|int $id = ''): self
    {
        return new self($id, self::SERVER_ERROR, $message);
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * @return array{
     *     jsonrpc: string,
     *     id: string|int,
     *     error: array{
     *         code: int,
     *         message: string,
     *     },
     *     data?: mixed,
     * }
     */
    public function jsonSerialize(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if (null !== $this->data) {
            $error['data'] = $this->data;
        }

        return [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $this->id,
            'error' => $error,
        ];
    }
}
