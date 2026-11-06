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
 * Describes a message returned as part of a prompt.
 *
 * @phpstan-import-type ContentBlockData from ContentBlock
 *
 * @phpstan-type PromptMessageData array{
 *     role: 'assistant'|'user',
 *     content: ContentBlockData,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class PromptMessage implements \JsonSerializable
{
    public function __construct(
        public readonly Role $role,
        public readonly ContentBlock $content,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['role'])) {
            throw new InvalidArgumentException('Missing "role" in PromptMessage data.');
        }
        $role = \is_string($data['role']) ? Role::tryFrom($data['role']) : null;
        if (null === $role) {
            throw new InvalidArgumentException('Invalid "role" in PromptMessage data.');
        }
        if (!isset($data['content'])) {
            throw new InvalidArgumentException('Missing "content" in PromptMessage data.');
        }
        if (!\is_array($data['content'])) {
            throw new InvalidArgumentException('Invalid "content" in PromptMessage data.');
        }
        $content = ContentBlockFactory::fromArray($data['content']);

        return new self(
            role: $role,
            content: $content,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'role' => $this->role->value,
            'content' => $this->content,
        ];

        return $data;
    }
}
