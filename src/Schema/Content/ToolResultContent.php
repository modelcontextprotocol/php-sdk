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
 * Carries the same content blocks as a `tools/call` result, so it accepts everything
 * {@see \Mcp\Schema\Result\CallToolResult} does.
 *
 * @phpstan-type ToolResultBlock TextContent|ImageContent|AudioContent|ResourceLink|EmbeddedResource
 *
 * @deprecated Deprecated as of protocol revision 2026-07-28 (SEP-2577). Still functional for at
 * least twelve months. Integrate with an LLM provider's API directly instead.
 */
final class ToolResultContent extends Content
{
    /**
     * @var list<ToolResultBlock>
     */
    public readonly array $content;

    /**
     * @param array<ToolResultBlock> $content           keys are discarded, the property always holds a list
     * @param ?array<string, mixed>  $structuredContent
     * @param ?array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $toolUseId,
        array $content,
        public readonly ?array $structuredContent = null,
        public readonly bool $isError = false,
        public readonly ?array $meta = null,
    ) {
        foreach ($content as $item) {
            if (!$item instanceof TextContent && !$item instanceof ImageContent && !$item instanceof AudioContent && !$item instanceof ResourceLink && !$item instanceof EmbeddedResource) {
                throw new InvalidArgumentException('Tool result content must contain standard content blocks.');
            }
        }

        // array_filter() and friends preserve keys, and a keyed array serializes
        // as a JSON object rather than the array the schema requires.
        $this->content = array_values($content);

        parent::__construct('tool_result');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['toolUseId']) || !\is_string($data['toolUseId'])) {
            throw new InvalidArgumentException('Missing or invalid "toolUseId" in ToolResultContent data.');
        }
        if (!isset($data['content']) || !\is_array($data['content'])) {
            throw new InvalidArgumentException('Missing or invalid "content" in ToolResultContent data.');
        }

        $content = [];
        foreach ($data['content'] as $item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('Invalid content block in ToolResultContent data.');
            }

            $content[] = match ($item['type'] ?? null) {
                'text' => TextContent::fromArray($item),
                'image' => ImageContent::fromArray($item),
                'audio' => AudioContent::fromArray($item),
                'resource_link' => ResourceLink::fromArray($item),
                'resource' => EmbeddedResource::fromArray($item),
                default => throw new InvalidArgumentException(\sprintf('Unsupported tool result content type "%s".', $item['type'] ?? null)),
            };
        }

        return new self(
            $data['toolUseId'],
            $content,
            isset($data['structuredContent']) && \is_array($data['structuredContent']) ? $data['structuredContent'] : null,
            isset($data['isError']) && true === $data['isError'],
            isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type,
            'toolUseId' => $this->toolUseId,
            'content' => $this->content,
        ];

        // Optional in the schema with a default of false, so only sent when it is true.
        if ($this->isError) {
            $data['isError'] = true;
        }

        if (null !== $this->structuredContent) {
            $data['structuredContent'] = $this->structuredContent;
        }
        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
