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
use Mcp\Schema\Annotations;
use Mcp\Schema\Concern\EmbeddedResourceFactories;

/**
 * The contents of a resource, embedded into a prompt or tool call result.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 * @phpstan-import-type BlobResourceContentsData from BlobResourceContents
 * @phpstan-import-type TextResourceContentsData from TextResourceContents
 *
 * @phpstan-type EmbeddedResourceData array{
 *     resource: TextResourceContentsData|BlobResourceContentsData,
 *     type: 'resource',
 *     annotations?: AnnotationsData,
 *     _meta?: array<string, mixed>,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class EmbeddedResource implements ContentBlock
{
    use EmbeddedResourceFactories;

    public const TYPE = 'resource';

    public readonly string $type;

    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly TextResourceContents|BlobResourceContents $resource,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
        $this->type = self::TYPE;
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('EmbeddedResource "_meta" must be a map, not a list.');
        }
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
            'type' => self::TYPE,
            'resource' => $this->resource,
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
