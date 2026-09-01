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

use Mcp\Schema\Enum\Role;
use Mcp\Schema\Exception\InvalidArgumentException;

/**
 * Describes a message issued to or received from an LLM API during sampling.
 *
 * Structural validity is enforced here, but the spec's tool-flow rules (which role may
 * carry which block, tool results not being mixed with other content, every tool use
 * being answered) are not: they span the whole message list and must be reportable as
 * an "invalid params" error rather than as a parse failure. They live in
 * {@see \Mcp\Schema\Request\CreateSamplingMessageRequest::validateToolFlow()}.
 *
 * @phpstan-type SamplingContent TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent
 * @phpstan-type SamplingMessageData array{
 *     role: 'user'|'assistant',
 *     content: array<string, mixed>|list<array<string, mixed>>,
 *     _meta?: array<string, mixed>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
class SamplingMessage extends Content
{
    /**
     * @var SamplingContent|list<SamplingContent>
     */
    public readonly TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array $content;

    /**
     * @param SamplingContent|array<SamplingContent> $content keys are discarded, the property always holds a list
     * @param ?array<string, mixed>                  $meta
     */
    public function __construct(
        public readonly Role $role,
        TextContent|ImageContent|AudioContent|ToolUseContent|ToolResultContent|array $content,
        public readonly ?array $meta = null,
    ) {
        if (\is_array($content)) {
            if ([] === $content) {
                throw new InvalidArgumentException('Sampling message content must not be empty.');
            }

            foreach ($content as $item) {
                if (!$item instanceof TextContent && !$item instanceof ImageContent && !$item instanceof AudioContent && !$item instanceof ToolUseContent && !$item instanceof ToolResultContent) {
                    throw new InvalidArgumentException('Sampling message content contains an unsupported content block.');
                }
            }

            // array_filter() and friends preserve keys, and a keyed array serializes
            // as a JSON object rather than the array the schema requires.
            $content = array_values($content);
        }

        $this->content = $content;

        parent::__construct('sampling');
    }

    /**
     * @return list<SamplingContent>
     */
    public function getContentBlocks(): array
    {
        return \is_array($this->content) ? $this->content : [$this->content];
    }

    /**
     * @param SamplingMessageData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !\is_string($data['role'])) {
            throw new InvalidArgumentException('Missing or invalid "role" in SamplingMessage data.');
        }
        if (!isset($data['content']) || !\is_array($data['content']) || [] === $data['content']) {
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
     * @return array{
     *     role: string,
     *     content: SamplingContent|list<SamplingContent>,
     *     _meta?: array<string, mixed>,
     * }
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
