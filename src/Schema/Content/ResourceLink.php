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
use Mcp\Schema\Icon;

/**
 * A resource that the server is capable of reading, included in a prompt or tool call result.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 * @phpstan-import-type IconData from Icon
 *
 * @phpstan-type ResourceLinkData array{
 *     uri: string,
 *     name: string,
 *     title?: string,
 *     description?: string,
 *     mimeType?: string,
 *     annotations?: AnnotationsData,
 *     size?: int,
 *     icons?: list<IconData>,
 *     _meta?: array<string, mixed>,
 *     type: 'resource_link',
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ResourceLink implements ContentBlock
{
    public const TYPE = 'resource_link';

    public readonly string $type;

    /**
     * @param array<Icon>|null          $icons since protocol revision 2025-11-25
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?Annotations $annotations = null,
        public readonly ?int $size = null,
        public readonly ?array $icons = null,
        public readonly ?array $meta = null,
    ) {
        if (null !== $this->icons) {
            foreach ($this->icons as $item) {
                if (!$item instanceof Icon) {
                    throw new InvalidArgumentException('Invalid "icons" item in ResourceLink.');
                }
            }
        }
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('ResourceLink "_meta" must be a map, not a list.');
        }
        $this->type = self::TYPE;
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['uri'])) {
            throw new InvalidArgumentException('Missing "uri" in ResourceLink data.');
        }
        if (!\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid "uri" in ResourceLink data.');
        }
        $uri = $data['uri'];
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Missing "name" in ResourceLink data.');
        }
        if (!\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid "name" in ResourceLink data.');
        }
        $name = $data['name'];
        $title = null;
        if (isset($data['title'])) {
            if (!\is_string($data['title'])) {
                throw new InvalidArgumentException('Invalid "title" in ResourceLink data.');
            }
            $title = $data['title'];
        }
        $description = null;
        if (isset($data['description'])) {
            if (!\is_string($data['description'])) {
                throw new InvalidArgumentException('Invalid "description" in ResourceLink data.');
            }
            $description = $data['description'];
        }
        $mimeType = null;
        if (isset($data['mimeType'])) {
            if (!\is_string($data['mimeType'])) {
                throw new InvalidArgumentException('Invalid "mimeType" in ResourceLink data.');
            }
            $mimeType = $data['mimeType'];
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in ResourceLink data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $size = null;
        if (isset($data['size'])) {
            if (!\is_int($data['size'])) {
                throw new InvalidArgumentException('Invalid "size" in ResourceLink data.');
            }
            $size = $data['size'];
        }
        $icons = null;
        if (isset($data['icons'])) {
            if (!\is_array($data['icons']) || !array_is_list($data['icons'])) {
                throw new InvalidArgumentException('Invalid "icons" in ResourceLink data.');
            }
            $icons = [];
            foreach ($data['icons'] as $item) {
                if (!\is_array($item)) {
                    throw new InvalidArgumentException('Invalid "icons" in ResourceLink data.');
                }
                $value = Icon::fromArray($item);
                $icons[] = $value;
            }
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in ResourceLink data.');
            }
            $meta = $data['_meta'];
        }
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in ResourceLink data.');
        }

        return new self(
            uri: $uri,
            name: $name,
            title: $title,
            description: $description,
            mimeType: $mimeType,
            annotations: $annotations,
            size: $size,
            icons: $icons,
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
            'uri' => $this->uri,
            'name' => $this->name,
        ];

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }

        if (null !== $this->size) {
            $data['size'] = $this->size;
        }

        if (null !== $this->icons) {
            $data['icons'] = array_values($this->icons);
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
