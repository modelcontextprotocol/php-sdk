<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Root;

/**
 * The client's response to a roots/list request from the server.
 * This result contains an array of Root objects, each representing a root directory
 * or file that the server can operate on.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListRootsResult implements ResultInterface
{
    /**
     * @param Root[]                $roots an array of root URIs
     * @param ?array<string, mixed> $meta  optional metadata about the result
     */
    public function __construct(
        public readonly array $roots,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array{
     *     roots: array<array{uri: string, name?: string}>,
     *     _meta?: ?array<string, mixed>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['roots']) || !\is_array($data['roots'])) {
            throw new InvalidArgumentException('Missing or invalid "roots" in ListRootsResult data.');
        }

        $roots = array_map(
            static fn (array $root): Root => Root::fromArray($root),
            array_values($data['roots']),
        );

        $meta = isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null;

        return new self($roots, $meta);
    }

    /**
     * @return array{
     *     roots: Root[],
     *     _meta?: ?array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'roots' => array_values($this->roots),
        ];

        if (null !== $this->meta) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
