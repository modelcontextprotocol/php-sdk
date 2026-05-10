<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

use Mcp\Exception\InvalidArgumentException;

/**
 * A template description for resources available on the server.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type ResourceTemplateData array{
 *     uriTemplate: string,
 *     name: string,
 *     title?: string,
 *     description?: string|null,
 *     mimeType?: string|null,
 *     annotations?: AnnotationsData|null,
 *     _meta?: array<string, mixed>
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceTemplate implements \JsonSerializable
{
    /**
     * Resource name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * URI Template pattern regex - requires a valid scheme followed by colon and path with at least one placeholder (RFC 3986).
     * Example patterns: file://{path}/contents.txt, db://{table}/{id}, config:{key}, etc.
     */
    private const URI_TEMPLATE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*:.*{[^{}]+}.*/';

    /**
     * @param string                $uriTemplate a URI template (according to RFC 6570) that can be used to construct resource URIs
     * @param string                $name        a short identifier for this resource template type
     * @param ?string               $title       optional human-readable title for display in UI
     * @param ?string               $description a description to help the LLM understand available resources
     * @param ?string               $mimeType    the MIME type for all resources that match this template, if uniform
     * @param ?Annotations          $annotations optional annotations for the client
     * @param ?array<string, mixed> $meta        optional metadata
     */
    public function __construct(
        public readonly string $uriTemplate,
        public readonly string $name,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
        if (!preg_match(self::RESOURCE_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException(\sprintf('Invalid resource name "%s": must contain only alphanumeric characters, underscores, and hyphens.', $name));
        }
        if (!preg_match(self::URI_TEMPLATE_PATTERN, $uriTemplate)) {
            throw new InvalidArgumentException(\sprintf('Invalid URI template : "%s" must be a valid URI template with at least one placeholder.', $uriTemplate));
        }
    }

    /**
     * @param ResourceTemplateData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['uriTemplate']) || !\is_string($data['uriTemplate'])) {
            throw new InvalidArgumentException('Invalid or missing "uriTemplate" in ResourceTemplate data.');
        }
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in ResourceTemplate data.');
        }

        if (!empty($data['_meta']) && !\is_array($data['_meta'])) {
            throw new InvalidArgumentException('Invalid "_meta" in ResourceTemplate data.');
        }

        return new self(
            uriTemplate: $data['uriTemplate'],
            name: $data['name'],
            title: isset($data['title']) && \is_string($data['title']) ? $data['title'] : null,
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null,
            meta: isset($data['_meta']) ? $data['_meta'] : null
        );
    }

    /**
     * @return array{
     *     uriTemplate: string,
     *     name: string,
     *     title?: string,
     *     description?: string,
     *     mimeType?: string,
     *     annotations?: Annotations,
     *     _meta?: array<string, mixed>
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'uriTemplate' => $this->uriTemplate,
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
        if (null !== $this->meta) {
            $data['_meta'] = $this->meta;
        }

        return $data;
    }
}
