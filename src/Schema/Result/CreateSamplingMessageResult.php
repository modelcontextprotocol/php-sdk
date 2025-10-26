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
use Mcp\Schema\Enum\Role;
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
     * @param Role                                  $role       the role of the message
     * @param TextContent|ImageContent|AudioContent $content    the content of the message
     * @param string                                $model      the name of the model that generated the message
     * @param string|null                           $stopReason the reason why sampling stopped, if known
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent $content,
        public readonly string $model,
        public readonly ?string $stopReason = null,
    ) {
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

        $role = Role::from($data['role']);
        $contentPayload = $data['content'];

        $content = self::hydrateContent($contentPayload);
        $stopReason = isset($data['stopReason']) && \is_string($data['stopReason']) ? $data['stopReason'] : null;

        return new self($role, $content, $data['model'], $stopReason);
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private static function hydrateContent(array $contentData): TextContent|ImageContent|AudioContent
    {
        $type = $contentData['type'] ?? null;

        if (!\is_string($type)) {
            throw new InvalidArgumentException('Missing or invalid "type" in sampling content payload.');
        }

        return match ($type) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            default => throw new InvalidArgumentException(\sprintf('Unsupported sampling content type "%s".', $type)),
        };
    }

    /**
     * @return array{
     *     role: string,
     *     content: TextContent|ImageContent|AudioContent,
     *     model: string,
     *     stopReason?: string,
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

        return $result;
    }
}
