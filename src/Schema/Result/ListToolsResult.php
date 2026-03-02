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
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Tool;

/**
 * The server's response to a tools/list request from the client.
 *
 * @phpstan-import-type ToolData from Tool
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListToolsResult implements ResultInterface
{
    /**
     * @param array<Tool> $tools      the list of tool definitions
     * @param string|null $nextCursor An opaque token representing the pagination position after the last returned result.
     *
     * If present, there may be more results available.
     */
    public function __construct(
        public readonly array $tools,
        public readonly ?string $nextCursor = null,
    ) {
    }

    /**
     * @param array{
     *     tools: array<ToolData>,
     *     nextCursor?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['tools']) || !\is_array($data['tools'])) {
            throw new InvalidArgumentException('Missing or invalid "tools" array in ListToolsResult data.');
        }

        return new self(
            array_map(static fn (array $tool) => Tool::fromArray($tool), $data['tools']),
            $data['nextCursor'] ?? null
        );
    }

    /**
     * @return array{
     *     tools: array<Tool>,
     *     nextCursor?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'tools' => array_values($this->tools),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
