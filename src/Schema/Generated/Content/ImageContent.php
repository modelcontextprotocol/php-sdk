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
use Mcp\Schema\Generated\Annotations;

/**
 * An image provided to or from an LLM.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ImageContent implements ContentBlock
{
    public const TYPE = 'image';

    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['data'])) {
            throw new InvalidArgumentException('Missing "data" in ImageContent data.');
        }
        if (!\is_string($data['data'])) {
            throw new InvalidArgumentException('Invalid "data" in ImageContent data.');
        }
        $dataValue = $data['data'];
        if (!isset($data['mimeType'])) {
            throw new InvalidArgumentException('Missing "mimeType" in ImageContent data.');
        }
        if (!\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Invalid "mimeType" in ImageContent data.');
        }
        $mimeType = $data['mimeType'];
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in ImageContent data.');
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in ImageContent data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in ImageContent data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            data: $dataValue,
            mimeType: $mimeType,
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
            'data' => $this->data,
            'mimeType' => $this->mimeType,
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
