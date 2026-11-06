<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

use Mcp\Exception\InvalidArgumentException;

/**
 * The result of a tool use, provided by the user back to the assistant.
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28
 *
 * @phpstan-import-type ContentBlockData from ContentBlock
 *
 * @phpstan-type ToolResultContentData array{
 *     toolUseId: string,
 *     content: list<ContentBlockData>,
 *     structuredContent?: mixed,
 *     isError?: bool,
 *     _meta?: array<string, mixed>,
 *     type: 'tool_result',
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ToolResultContent implements SamplingMessageContentBlock
{
    public const TYPE = 'tool_result';

    public readonly string $type;

    /**
     * @param array<ContentBlock>       $content
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly string $toolUseId,
        public readonly array $content,
        public readonly mixed $structuredContent = null,
        public readonly bool $isError = false,
        public readonly ?array $meta = null,
    ) {
        foreach ($this->content as $item) {
            if (!$item instanceof ContentBlock) {
                throw new InvalidArgumentException('Invalid "content" item in ToolResultContent.');
            }
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('ToolResultContent "_meta" must be a map, not a list.');
        }
        $this->type = self::TYPE;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['toolUseId'])) {
            throw new InvalidArgumentException('Missing "toolUseId" in ToolResultContent data.');
        }
        if (!\is_string($data['toolUseId'])) {
            throw new InvalidArgumentException('Invalid "toolUseId" in ToolResultContent data.');
        }
        $toolUseId = $data['toolUseId'];
        if (!isset($data['content'])) {
            throw new InvalidArgumentException('Missing "content" in ToolResultContent data.');
        }
        if (!\is_array($data['content']) || !array_is_list($data['content'])) {
            throw new InvalidArgumentException('Invalid "content" in ToolResultContent data.');
        }
        $content = [];
        foreach ($data['content'] as $item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('Invalid "content" in ToolResultContent data.');
            }
            $value = ContentBlockFactory::fromArray($item);
            $content[] = $value;
        }
        $structuredContent = $data['structuredContent'] ?? null;
        $isError = false;
        if (isset($data['isError'])) {
            if (!\is_bool($data['isError'])) {
                throw new InvalidArgumentException('Invalid "isError" in ToolResultContent data.');
            }
            $isError = $data['isError'];
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in ToolResultContent data.');
            }
            $meta = $data['_meta'];
        }
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in ToolResultContent data.');
        }

        return new self(
            toolUseId: $toolUseId,
            content: $content,
            structuredContent: $structuredContent,
            isError: $isError,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => self::TYPE,
            'toolUseId' => $this->toolUseId,
            'content' => array_values($this->content),
        ];

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }

        if (false !== $this->isError) {
            $data['isError'] = $this->isError;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
