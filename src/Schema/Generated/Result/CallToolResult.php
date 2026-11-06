<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Generated\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Concern\CallToolResultFactories;
use Mcp\Schema\Generated\Content\ContentBlock;
use Mcp\Schema\Generated\Content\ContentBlockFactory;

/**
 * The result returned by the server for a `tools/call` request.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class CallToolResult implements \JsonSerializable
{
    use CallToolResultFactories;

    /**
     * @param list<ContentBlock>        $content
     * @param mixed                     $structuredContent since protocol revision 2025-06-18
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly array $content,
        public readonly ?bool $isError = null,
        public readonly mixed $structuredContent = null,
        public readonly ?array $meta = null,
    ) {
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
        $isError = null;
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
            'content' => $this->content,
        ];

        if (null !== $this->isError) {
            $data['isError'] = $this->isError;
        }

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
