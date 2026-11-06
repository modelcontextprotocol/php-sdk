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
use Mcp\Schema\Concern\CallToolResultFactories;
use Mcp\Schema\Content\ContentBlock;
use Mcp\Schema\Content\ContentBlockFactory;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The result returned by the server for a `tools/call` request.
 *
 * @phpstan-import-type ContentBlockData from ContentBlock
 *
 * @phpstan-type CallToolResultData array{
 *     content: list<ContentBlockData>,
 *     isError?: bool,
 *     structuredContent?: mixed,
 *     _meta?: array<string, mixed>,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class CallToolResult implements ResultInterface
{
    use CallToolResultFactories;

    /**
     * @param array<ContentBlock>       $content
     * @param mixed                     $structuredContent since protocol revision 2025-06-18
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
        public readonly mixed $structuredContent = null,
        public readonly ?array $meta = null,
    ) {
        foreach ($this->content as $item) {
            if (!$item instanceof ContentBlock) {
                throw new InvalidArgumentException('Invalid "content" item in CallToolResult.');
            }
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('CallToolResult "_meta" must be a map, not a list.');
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['content'])) {
            throw new InvalidArgumentException('Missing "content" in CallToolResult data.');
        }
        if (!\is_array($data['content']) || !array_is_list($data['content'])) {
            throw new InvalidArgumentException('Invalid "content" in CallToolResult data.');
        }
        $content = [];
        foreach ($data['content'] as $item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('Invalid "content" in CallToolResult data.');
            }
            $value = ContentBlockFactory::fromArray($item);
            $content[] = $value;
        }
        $isError = false;
        if (isset($data['isError'])) {
            if (!\is_bool($data['isError'])) {
                throw new InvalidArgumentException('Invalid "isError" in CallToolResult data.');
            }
            $isError = $data['isError'];
        }
        $structuredContent = $data['structuredContent'] ?? null;
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in CallToolResult data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            content: $content,
            isError: $isError,
            structuredContent: $structuredContent,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'content' => array_values($this->content),
            'isError' => $this->isError,
        ];

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
