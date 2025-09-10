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
 * Describes a message issued to or received from an LLM API during sampling.
 *
 * @phpstan-type SamplingMessageData = array{
 *     role: string,
 *     content: TextContent|ImageContent|AudioContent
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SamplingMessage extends Content
{
    public function __construct(
        public readonly Role $role,
        public readonly TextContent|ImageContent|AudioContent $content,
    ) {
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

        $role = Role::from($data['role']);
        $contentData = $data['content'];
        $contentType = $contentData['type'] ?? null;

        $contentInstance = match ($contentType) {
            'text' => TextContent::fromArray($contentData),
            'image' => ImageContent::fromArray($contentData),
            'audio' => AudioContent::fromArray($contentData),
            default => throw new InvalidArgumentException(\sprintf('Invalid content type "%s" for SamplingMessage.', $contentType)),
        };

        return new self($role, $contentInstance);
    }

    /**
     * @return SamplingMessageData
     */
    public function jsonSerialize(): array
    {
        return [
            'role' => $this->role->value,
            'content' => $this->content,
        ];
    }
}
