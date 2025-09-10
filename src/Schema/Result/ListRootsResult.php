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

namespace Mcp\Schema\Result;

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
     * @param ?array<string, mixed> $_meta optional metadata about the result
     */
    public function __construct(
        public readonly array $roots,
        public readonly ?array $_meta = null,
    ) {
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

        if (null !== $this->_meta) {
            $result['_meta'] = $this->_meta;
        }

        return $result;
    }
}
