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
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28
 *
 * @phpstan-type ToolUseContentData array{
 *     id: string,
 *     name: string,
 *     input: array<string, mixed>,
 *     _meta?: array<string, mixed>,
 *     type: 'tool_use',
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ToolUseContent implements SamplingMessageContentBlock
{
    public const TYPE = 'tool_use';

    public readonly string $type;

    /**
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $input,
        public readonly ?array $meta = null,
    ) {
        if ([] !== $this->input && array_is_list($this->input)) {
            throw new InvalidArgumentException('ToolUseContent "input" must be a map, not a list.');
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('ToolUseContent "_meta" must be a map, not a list.');
        }
        $this->type = self::TYPE;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'])) {
            throw new InvalidArgumentException('Missing "id" in ToolUseContent data.');
        }
        if (!\is_string($data['id'])) {
            throw new InvalidArgumentException('Invalid "id" in ToolUseContent data.');
        }
        $id = $data['id'];
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Missing "name" in ToolUseContent data.');
        }
        if (!\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid "name" in ToolUseContent data.');
        }
        $name = $data['name'];
        if (!isset($data['input'])) {
            throw new InvalidArgumentException('Missing "input" in ToolUseContent data.');
        }
        if (!\is_array($data['input'])) {
            throw new InvalidArgumentException('Invalid "input" in ToolUseContent data.');
        }
        $input = $data['input'];
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in ToolUseContent data.');
            }
            $meta = $data['_meta'];
        }
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in ToolUseContent data.');
        }

        return new self(
            id: $id,
            name: $name,
            input: $input,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => self::TYPE,
            'id' => $this->id,
            'name' => $this->name,
            'input' => [] === $this->input ? new \stdClass() : $this->input,
        ];

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
