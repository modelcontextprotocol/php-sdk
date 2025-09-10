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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's response to a completion/complete request.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class CompletionCompleteResult implements ResultInterface
{
    /**
     * @param string[]  $values  An array of completion values. Must not exceed 100 items.
     * @param int|null  $total   The total number of completion options available. This can exceed the number of values actually sent in the response.
     * @param bool|null $hasMore indicates whether there are additional completion options beyond those provided in the current response, even if the exact total is unknown
     */
    public function __construct(
        public readonly array $values,
        public readonly ?int $total = null,
        public readonly ?bool $hasMore = null,
    ) {
        if (\count($this->values) > 100) {
            throw new InvalidArgumentException('Values must not exceed 100 items');
        }
    }

    /**
     * @return array{
     *     completion: array{
     *         values: string[],
     *         total?: int,
     *         hasMore?: bool,
     *     }
     * }
     */
    public function jsonSerialize(): array
    {
        $completion = [
            'values' => $this->values,
        ];

        if (null !== $this->total) {
            $completion['total'] = $this->total;
        }
        if (null !== $this->hasMore) {
            $completion['hasMore'] = $this->hasMore;
        }

        return ['completion' => $completion];
    }
}
