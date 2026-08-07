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
 * A request from the assistant to call a tool.
 */
final class ToolUseContent extends Content
{
    /**
     * @param array<string, mixed>  $input
     * @param ?array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        public readonly ?array $meta = null,
    ) {
        parent::__construct('tool_use');
    }

    /**
     * @param array{id?: mixed, name?: mixed, input?: mixed, _meta?: mixed} $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !\is_string($data['id'])) {
            throw new InvalidArgumentException('Missing or invalid "id" in ToolUseContent data.');
        }
        if (!isset($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Missing or invalid "name" in ToolUseContent data.');
        }
        if (!isset($data['input']) || !\is_array($data['input'])) {
            throw new InvalidArgumentException('Missing or invalid "input" in ToolUseContent data.');
        }

        return new self(
            $data['id'],
            $data['name'],
            $data['input'],
            isset($data['_meta']) && \is_array($data['_meta']) ? $data['_meta'] : null,
        );
    }

    /**
     * @return array{type: 'tool_use', id: string, name: string, input: array<string, mixed>|\stdClass, _meta?: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
            'input' => $this->input ?: new \stdClass(),
        ];

        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
