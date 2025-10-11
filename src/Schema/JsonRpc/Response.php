<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\JsonRpc;

use Mcp\Exception\InvalidArgumentException;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @phpstan-type ResponseData array{
 *     jsonrpc: string,
 *     id: string|int,
 *     result: array<string, mixed>,
 * }
 */
class Response implements MessageInterface
{
    /**
     * @param string|int                           $id     this MUST be the same as the value of the id member in the Request Object
     * @param ResultInterface|array<string, mixed> $result the value of this member is determined by the method invoked on the Server
     */
    public function __construct(
        public readonly string|int $id,
        public readonly ResultInterface|array $result,
    ) {
    }

    public function getId(): string|int
    {
        return $this->id;
    }

    /**
     * @param ResponseData $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['jsonrpc'] ?? null) !== MessageInterface::JSONRPC_VERSION) {
            throw new InvalidArgumentException('Invalid or missing "jsonrpc" version for Response.');
        }
        if (!isset($data['id'])) {
            throw new InvalidArgumentException('Missing "id" for Response.');
        }
        if (!\is_string($data['id']) && !\is_int($data['id'])) {
            throw new InvalidArgumentException('Invalid "id" type for Response.');
        }
        if (!isset($data['result'])) {
            throw new InvalidArgumentException('Response must contain "result" field.');
        }
        if (!\is_array($data['result'])) {
            throw new InvalidArgumentException('Response "result" must be an array.');
        }

        return new self($data['id'], $data['result']);
    }

    /**
     * @return array{
     *     jsonrpc: string,
     *     id: string|int,
     *     result: ResultInterface,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'jsonrpc' => MessageInterface::JSONRPC_VERSION,
            'id' => $this->id,
            'result' => $this->result,
        ];
    }
}
