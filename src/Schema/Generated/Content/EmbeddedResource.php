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
 * The contents of a resource, embedded into a prompt or tool call result.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class EmbeddedResource implements ContentBlock
{
    public const TYPE = 'resource';

    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly TextResourceContents|BlobResourceContents $resource,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['resource'])) {
            throw new InvalidArgumentException('Missing "resource" in EmbeddedResource data.');
        }
        if (!\is_array($data['resource'])) {
            throw new InvalidArgumentException('Invalid "resource" in EmbeddedResource data.');
        }
        if (\array_key_exists('text', $data['resource'])) {
            $resource = TextResourceContents::fromArray($data['resource']);
        } elseif (\array_key_exists('blob', $data['resource'])) {
            $resource = BlobResourceContents::fromArray($data['resource']);
        } else {
            throw new InvalidArgumentException('Invalid "resource" in EmbeddedResource data.');
        }
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in EmbeddedResource data.');
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in EmbeddedResource data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in EmbeddedResource data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            resource: $resource,
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
            'resource' => $this->resource,
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
