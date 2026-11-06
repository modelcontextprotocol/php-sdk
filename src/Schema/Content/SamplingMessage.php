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
use Mcp\Schema\Concern\SamplingMessageContentBlocks;
use Mcp\Schema\Enum\Role;

/**
 * Describes a message issued to or received from an LLM API.
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28
 *
 * @phpstan-import-type AudioContentData from AudioContent
 * @phpstan-import-type ImageContentData from ImageContent
 * @phpstan-import-type SamplingMessageContentBlockData from SamplingMessageContentBlock
 * @phpstan-import-type TextContentData from TextContent
 * @phpstan-import-type ToolResultContentData from ToolResultContent
 * @phpstan-import-type ToolUseContentData from ToolUseContent
 *
 * @phpstan-type SamplingMessageData array{
 *     role: 'assistant'|'user',
 *     content: TextContentData|ImageContentData|AudioContentData|ToolUseContentData|ToolResultContentData|list<SamplingMessageContentBlockData>,
 *     _meta?: array<string, mixed>,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class SamplingMessage implements \JsonSerializable
{
    use SamplingMessageContentBlocks;

    /**
     * @param TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array<SamplingMessageContentBlock> $content
     * @param array<string, mixed>|null                                                                                 $meta    since protocol revision 2025-11-25
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array $content,
        public readonly ?array $meta = null,
    ) {
        if (\is_array($this->content)) {
            foreach ($this->content as $item) {
                if (!$item instanceof SamplingMessageContentBlock) {
                    throw new InvalidArgumentException('Invalid "content" item in SamplingMessage.');
                }
            }
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('SamplingMessage "_meta" must be a map, not a list.');
        }
        $this->validateContentNotEmpty();
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role'])) {
            throw new InvalidArgumentException('Missing "role" in SamplingMessage data.');
        }
        $role = \is_string($data['role']) ? Role::tryFrom($data['role']) : null;
        if (null === $role) {
            throw new InvalidArgumentException('Invalid "role" in SamplingMessage data.');
        }
        if (!isset($data['content'])) {
            throw new InvalidArgumentException('Missing "content" in SamplingMessage data.');
        }
        if (!\is_array($data['content'])) {
            throw new InvalidArgumentException('Invalid "content" in SamplingMessage data.');
        }
        if (array_is_list($data['content'])) {
            $content = [];
            foreach ($data['content'] as $item) {
                if (!\is_array($item)) {
                    throw new InvalidArgumentException('Invalid "content" in SamplingMessage data.');
                }
                $value = SamplingMessageContentBlockFactory::fromArray($item);
                $content[] = $value;
            }
        } else {
            $content = match ($data['content']['type'] ?? null) {
                TextContent::TYPE => TextContent::fromArray($data['content']),
                ImageContent::TYPE => ImageContent::fromArray($data['content']),
                AudioContent::TYPE => AudioContent::fromArray($data['content']),
                ToolUseContent::TYPE => ToolUseContent::fromArray($data['content']),
                ToolResultContent::TYPE => ToolResultContent::fromArray($data['content']),
                default => throw new InvalidArgumentException('Invalid "content" in SamplingMessage data.'),
            };
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in SamplingMessage data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            role: $role,
            content: $content,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'role' => $this->role->value,
            'content' => \is_array($this->content) ? array_values($this->content) : $this->content,
        ];

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
