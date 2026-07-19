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
 * Represents a link to a resource, without embedding its contents.
 *
 * A resource that the server is capable of reading, included in a prompt or
 * tool call result. Unlike `EmbeddedResource`, this points to a resource
 * without including its full content, allowing clients to fetch it separately.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 * @phpstan-import-type IconData from Icon
 *
 * @phpstan-type ResourceLinkData array{
 *     type: 'resource_link',
 *     uri: string,
 *     name: string,
 *     title?: string,
 *     description?: string,
 *     mimeType?: string,
 *     annotations?: AnnotationsData,
 *     size?: int,
 *     icons?: IconData[],
 *     _meta?: array<string, mixed>,
 * }
 *
 * @author Alex Rothberg <cancan101@users.noreply.github.com>
 */
class ResourceLink extends Content
{
    /**
     * @param string                $uri         the URI of this resource
     * @param string                $name        a short identifier for this resource
     * @param ?string               $title       optional human-readable title for display in UI
     * @param ?string               $description a description of what this resource represents. This can be used by clients to improve the LLM's understanding of available resources
     * @param ?string               $mimeType    the MIME type of this resource, if known
     * @param ?Annotations          $annotations optional annotations for the client
     * @param ?int                  $size        the size of the raw resource content, in bytes (before base64 encoding or any tokenization), if known
     * @param ?Icon[]               $icons       optional icons representing the resource
     * @param ?array<string, mixed> $meta        optional metadata
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
        parent::__construct('resource_link');
    }

    /**
     * @param ResourceLinkData $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'resource_link') {
            throw new InvalidArgumentException('Invalid type for ResourceLink.');
        }
        if (empty($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" in ResourceLink data.');
        }
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in ResourceLink data.');
        }
        if (isset($data['_meta']) && !\is_array($data['_meta'])) {
            throw new InvalidArgumentException('Invalid "_meta" in ResourceLink data.');
        }

        return new self(
            uri: $data['uri'],
            name: $data['name'],
            title: isset($data['title']) && \is_string($data['title']) ? $data['title'] : null,
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null,
            size: isset($data['size']) ? (int) $data['size'] : null,
            icons: isset($data['icons']) && \is_array($data['icons']) ? array_map(Icon::fromArray(...), $data['icons']) : null,
            meta: $data['_meta'] ?? null,
        );
    }

    /**
     * @return array{
     *     type: 'resource_link',
     *     uri: string,
     *     name: string,
     *     title?: string,
     *     description?: string,
     *     mimeType?: string,
     *     annotations?: Annotations,
     *     size?: int,
     *     icons?: Icon[],
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type,
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
            $data['icons'] = $this->icons;
        }
        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
