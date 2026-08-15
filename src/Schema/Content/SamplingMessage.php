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
use Mcp\Schema\Enum\Role;

/**
 * Describes a message issued to or received from an LLM API during sampling.
 *
 * @phpstan-type SamplingContent TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent
 * @phpstan-type SamplingMessageData = array{
 *     role: 'user'|'assistant',
 *     content: array<string, mixed>|array<array<string, mixed>>,
 *     _meta?: array<string, mixed>
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SamplingMessage extends Content
{
    /**
     * @param SamplingContent|list<SamplingContent> $content
     * @param ?array<string, mixed>                 $meta
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array $content,
        public readonly ?array $meta = null,
    ) {
        $contents = \is_array($content) ? $content : [$content];
        foreach ($contents as $item) {
            if (!$item instanceof TextContent && !$item instanceof ImageContent && !$item instanceof AudioContent && !$item instanceof ToolUseContent && !$item instanceof ToolResultContent) {
                throw new InvalidArgumentException('Sampling message content contains an unsupported content block.');
            }
            if (Role::User === $role && $item instanceof ToolUseContent) {
                throw new InvalidArgumentException('ToolUseContent is only valid in assistant sampling messages.');
            }
            if (Role::Assistant === $role && $item instanceof ToolResultContent) {
                throw new InvalidArgumentException('ToolResultContent is only valid in user sampling messages.');
            }
        }

        if (array_filter($contents, static fn ($item): bool => $item instanceof ToolResultContent)
            && array_filter($contents, static fn ($item): bool => !$item instanceof ToolResultContent)) {
            throw new InvalidArgumentException('Tool result messages must not contain other content types.');
        }

        parent::__construct('sampling');
    }

    /**
     * @param SamplingMessageData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !\is_string($data['role'])) {
            throw new InvalidArgumentException('Missing or invalid "role" in SamplingMessage data.');
        }
        if (!isset($data['content']) || !\is_array($data['content'])) {
            throw new InvalidArgumentException('Missing or invalid "content" in SamplingMessage data.');
        }

        if (null === $role = Role::tryFrom($data['role'])) {
            throw new InvalidArgumentException(\sprintf('Invalid "role" value "%s" in SamplingMessage data.', $data['role']));
        }

        $contentData = $data['content'];
        $contentType = $contentData['type'] ?? null;
        if (null !== $contentType && !\is_string($contentType)) {
            throw new InvalidArgumentException('Missing or invalid content "type" for SamplingMessage.');
        }

        $isSingleContent = null !== $contentType;
        $contentItems = $isSingleContent ? [$contentData] : $contentData;
        $content = [];

        foreach ($contentItems as $item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('Invalid content block in SamplingMessage data.');
            }
            $content[] = self::hydrateContent($item);
        }

        return new self(
            $role,
            $isSingleContent ? $content[0] : $content,
            isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
        );
    }

    /**
     * @return SamplingMessageData
     */
    public function jsonSerialize(): array
    {
        $data = [
            'role' => $this->role->value,
            'content' => $this->content,
        ];

        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $contentData
     *
     * @return SamplingContent
     */
    private static function hydrateContent(array $contentData): TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent
    {
        $contentType = $contentData['type'] ?? null;

        return match ($contentType) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            'tool_use' => ToolUseContent::fromArray($contentData),
            'tool_result' => ToolResultContent::fromArray($contentData),
            default => throw new InvalidArgumentException(\sprintf('Invalid content type "%s" for SamplingMessage.', $contentType)),
        };
    }
}
