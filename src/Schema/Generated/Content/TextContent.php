<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Generated\Content;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Concern\TextContentFactories;
use Mcp\Schema\Generated\Annotations;

/**
 * Text provided to or from an LLM.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class TextContent implements ContentBlock
{
    use TextContentFactories;

    public const TYPE = 'text';

    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly string $text,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['text'])) {
            throw new InvalidArgumentException('Missing "text" in TextContent data.');
        }
        if (!\is_string($data['text'])) {
            throw new InvalidArgumentException('Invalid "text" in TextContent data.');
        }
        $text = $data['text'];
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in TextContent data.');
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in TextContent data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in TextContent data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            text: $text,
            annotations: $annotations,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'text' => $this->text,
            'type' => self::TYPE,
        ];

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
