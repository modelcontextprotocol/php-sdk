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
use Mcp\Schema\Enum\SamplingStopReason;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The client's response to a sampling/create_message request from the server. The client should inform the user before
 * returning the sampled message, to allow them to inspect the response (human in the loop) and decide whether to allow
 * the server to see it.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class CreateSamplingMessageResult implements ResultInterface
{
    /**
     * @param Role                                                                                                            $role       the role of the message
     * @param TextContent|ImageContent|AudioContent|ToolUseContent|list<TextContent|ImageContent|AudioContent|ToolUseContent> $content    the content of the message
     * @param string                                                                                                          $model      the name of the model that generated the message
     * @param SamplingStopReason|string|null                                                                                  $stopReason the reason why sampling stopped, if known
     * @param ?array<string, mixed>                                                                                           $meta       optional message metadata
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|ToolUseContent|array $content,
        public readonly string $model,
        public readonly SamplingStopReason|string|null $stopReason = null,
        public readonly ?array $meta = null,
    ) {
        if (Role::Assistant !== $role) {
            throw new InvalidArgumentException('CreateSamplingMessageResult role must be "assistant".');
        }

        foreach (\is_array($content) ? $content : [$content] as $item) {
            if (!$item instanceof TextContent && !$item instanceof ImageContent && !$item instanceof AudioContent && !$item instanceof ToolUseContent) {
                throw new InvalidArgumentException('CreateSamplingMessageResult contains an unsupported content block.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !\is_string($data['role'])) {
            throw new InvalidArgumentException('Missing or invalid "role" in CreateSamplingMessageResult data.');
        }

        if (!isset($data['content']) || !\is_array($data['content'])) {
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

        $stopReason = null;
        if (isset($data['stopReason']) && \is_string($data['stopReason'])) {
            $stopReason = SamplingStopReason::tryFrom($data['stopReason']) ?? $data['stopReason'];
        }

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
            $result['stopReason'] = $this->stopReason instanceof SamplingStopReason ? $this->stopReason->value : $this->stopReason;
        }

        if (null !== $this->meta) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
