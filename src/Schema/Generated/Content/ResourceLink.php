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
use Mcp\Schema\Generated\Icon;

/**
 * A resource that the server is capable of reading, included in a prompt or tool call result.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class ResourceLink implements ContentBlock
{
    public const TYPE = 'resource_link';

    /**
     * @param list<Icon>|null           $icons since protocol revision 2025-11-25
     * @param array<string, mixed>|null $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $uri,
        public readonly ?Annotations $annotations = null,
        public readonly ?string $description = null,
        public readonly ?array $icons = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
        public readonly ?string $title = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['name'])) {
            throw new InvalidArgumentException('Missing "name" in ResourceLink data.');
        }
        if (!\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid "name" in ResourceLink data.');
        }
        $name = $data['name'];
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in ResourceLink data.');
        }
        if (!isset($data['uri'])) {
            throw new InvalidArgumentException('Missing "uri" in ResourceLink data.');
        }
        if (!\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid "uri" in ResourceLink data.');
        }
        $uri = $data['uri'];
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in ResourceLink data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $description = null;
        if (isset($data['description'])) {
            if (!\is_string($data['description'])) {
                throw new InvalidArgumentException('Invalid "description" in ResourceLink data.');
            }
            $description = $data['description'];
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
        $mimeType = null;
        if (isset($data['mimeType'])) {
            if (!\is_string($data['mimeType'])) {
                throw new InvalidArgumentException('Invalid "mimeType" in ResourceLink data.');
            }
            $mimeType = $data['mimeType'];
        }
        $size = null;
        if (isset($data['size'])) {
            if (!\is_int($data['size'])) {
                throw new InvalidArgumentException('Invalid "size" in ResourceLink data.');
            }
            $size = $data['size'];
        }
        $title = null;
        if (isset($data['title'])) {
            if (!\is_string($data['title'])) {
                throw new InvalidArgumentException('Invalid "title" in ResourceLink data.');
            }
            $title = $data['title'];
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in ResourceLink data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            name: $name,
            uri: $uri,
            annotations: $annotations,
            description: $description,
            icons: $icons,
            mimeType: $mimeType,
            size: $size,
            title: $title,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'type' => self::TYPE,
            'uri' => $this->uri,
        ];

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }

        if (null !== $this->description) {
            $data['description'] = $this->description;
        }

        if (null !== $this->icons) {
            $data['icons'] = $this->icons;
        }

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->size) {
            $data['size'] = $this->size;
        }

        if (null !== $this->title) {
            $data['title'] = $this->title;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
