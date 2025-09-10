<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\Content;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\Role;

/**
 * Describes a message returned as part of a prompt.
 *
 * @phpstan-import-type TextContentData from TextContent
 * @phpstan-import-type ImageContentData from ImageContent
 * @phpstan-import-type AudioContentData from AudioContent
 * @phpstan-import-type EmbeddedResourceData from EmbeddedResource
 *
 * @phpstan-type PromptMessageData array{
 *     role: string,
 *     content: TextContentData|ImageContentData|AudioContentData|EmbeddedResourceData,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class PromptMessage extends Content
{
    /**
     * Create a new PromptMessage instance.
     *
     * @param Role                                                   $role    The role of the message
     * @param TextContent|ImageContent|AudioContent|EmbeddedResource $content The content of the message
     */
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent|EmbeddedResource $content,
    ) {
        parent::__construct('prompt');
    }

    /**
     * @param PromptMessageData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role']) || !\is_string($data['role'])) {
            throw new InvalidArgumentException('Missing or invalid "role" in PromptMessage data.');
        }
        if (!isset($data['content']) || !\is_array($data['content'])) {
            throw new InvalidArgumentException('Missing or invalid "content" in PromptMessage data.');
        }

        $contentData = $data['content'];
        $contentType = $contentData['type'] ?? null;

        $content = match ($contentType) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            'resource' => EmbeddedResource::fromArray($contentData),
            default => throw new InvalidArgumentException(\sprintf('Invalid content type "%s" for PromptMessage.', $contentType)),
        };

        return new self(Role::from($data['role']), $content);
    }

    /**
     * Convert the message to an array.
     *
     * @return array{
     *     role: string,
     *     content: TextContent|ImageContent|AudioContent|EmbeddedResource
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
    }
}
