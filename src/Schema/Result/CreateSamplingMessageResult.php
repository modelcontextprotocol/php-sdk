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
use Mcp\Schema\Content\AudioContent;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Content\ToolUseContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The client's response to a sampling/create_message request from the server. The client should inform the user before
 * returning the sampled message, to allow them to inspect the response (human in the loop) and decide whether to allow
 * the server to see it.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
class CreateSamplingMessageResult implements ResultInterface
{
    /**
     * @var TextContent|ImageContent|AudioContent|ToolUseContent|list<TextContent|ImageContent|AudioContent|ToolUseContent>
     */
    public readonly TextContent|ImageContent|AudioContent|ToolUseContent|array $content;

    /**
     * @param Role                                                                                                             $role       the role of the message
     * @param TextContent|ImageContent|AudioContent|ToolUseContent|array<TextContent|ImageContent|AudioContent|ToolUseContent> $content    The content of the message. Keys are discarded, the property always holds a list.
     * @param string                                                                                                           $model      the name of the model that generated the message
     * @param ?string                                                                                                          $stopReason The reason why sampling stopped, if known. The spec defines "endTurn",
     *                                                                                                                                     "stopSequence", "maxTokens" and "toolUse", but leaves the set open for
     *                                                                                                                                     provider-specific values, so this stays an unconstrained string.
     * @param ?array<string, mixed>                                                                                            $meta       optional message metadata
     */
    public function __construct(
        public readonly Role $role,
        TextContent|ImageContent|AudioContent|ToolUseContent|array $content,
        public readonly string $model,
        public readonly ?string $stopReason = null,
        public readonly ?array $meta = null,
    ) {
        if (Role::Assistant !== $role) {
            throw new InvalidArgumentException('CreateSamplingMessageResult role must be "assistant".');
        }

        if (\is_array($content)) {
            if ([] === $content) {
                throw new InvalidArgumentException('CreateSamplingMessageResult content must not be empty.');
            }

            foreach ($content as $item) {
                if (!$item instanceof TextContent && !$item instanceof ImageContent && !$item instanceof AudioContent && !$item instanceof ToolUseContent) {
                    throw new InvalidArgumentException('CreateSamplingMessageResult contains an unsupported content block.');
                }
            }

            // array_filter() and friends preserve keys, and a keyed array serializes
            // as a JSON object rather than the array the schema requires.
            $content = array_values($content);
        }

        $this->content = $content;
    }

    /**
     * @return list<TextContent|ImageContent|AudioContent|ToolUseContent>
     */
    public function getContentBlocks(): array
    {
        return \is_array($this->content) ? $this->content : [$this->content];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !\is_string($data['role'])) {
            throw new InvalidArgumentException('Missing or invalid "role" in CreateSamplingMessageResult data.');
        }

        if (!isset($data['content']) || !\is_array($data['content']) || [] === $data['content']) {
            throw new InvalidArgumentException('Missing or invalid "content" in CreateSamplingMessageResult data.');
        }

        if (!isset($data['model']) || !\is_string($data['model'])) {
            throw new InvalidArgumentException('Missing or invalid "model" in CreateSamplingMessageResult data.');
        }

        if (null === $role = Role::tryFrom($data['role'])) {
            throw new InvalidArgumentException(\sprintf('Invalid "role" value "%s" in CreateSamplingMessageResult data.', $data['role']));
        }

        $contentPayload = $data['content'];

        $isSingleContent = isset($contentPayload['type']);
        $contentItems = $isSingleContent ? [$contentPayload] : $contentPayload;
        $content = [];
        foreach ($contentItems as $item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('Invalid content block in CreateSamplingMessageResult data.');
            }
            $content[] = self::hydrateContent($item);
        }

        $stopReason = isset($data['stopReason']) && \is_string($data['stopReason']) ? $data['stopReason'] : null;

        return new self(
            $role,
            $isSingleContent ? $content[0] : $content,
            $data['model'],
            $stopReason,
            isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
        );
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private static function hydrateContent(array $contentData): TextContent|ImageContent|AudioContent|ToolUseContent
    {
        $type = $contentData['type'] ?? null;

        if (!\is_string($type)) {
            throw new InvalidArgumentException('Missing or invalid "type" in sampling content payload.');
        }

        return match ($type) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            'tool_use' => ToolUseContent::fromArray($contentData),
            default => throw new InvalidArgumentException(\sprintf('Unsupported sampling content type "%s".', $type)),
        };
    }

    /**
     * @return array{
     *     role: string,
     *     content: TextContent|ImageContent|AudioContent|ToolUseContent|list<TextContent|ImageContent|AudioContent|ToolUseContent>,
     *     model: string,
     *     stopReason?: string,
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'role' => $this->role->value,
            'content' => $this->content,
            'model' => $this->model,
        ];

        if (null !== $this->stopReason) {
            $result['stopReason'] = $this->stopReason;
        }

        if (null !== $this->meta) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
