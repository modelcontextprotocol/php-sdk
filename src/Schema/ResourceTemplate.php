<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema;

use JsonSerializable;
use Mcp\Exception\InvalidArgumentException;

/**
 * A template description for resources available on the server.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type ResourceTemplateData array{
 *     uriTemplate: string,
 *     name: string,
 *     description?: string|null,
 *     mimeType?: string|null,
 *     annotations?: AnnotationsData|null,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ResourceTemplate implements JsonSerializable
{
    /**
     * Resource name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * URI Template pattern regex - requires a valid scheme, followed by colon and path with at least one placeholder.
     * Example patterns: config://{key}, file://{path}/contents.txt, db://{table}/{id}, etc.
     */
    private const URI_TEMPLATE_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/.*{[^{}]+}.*/';

    /**
     * @param string           $uriTemplate a URI template (according to RFC 6570) that can be used to construct resource URIs
     * @param string           $name        A human-readable name for the type of resource this template refers to. This can be used by clients to populate UI elements.
     * @param string|null      $description This can be used by clients to improve the LLM's understanding of available resources. It can be thought of like a "hint" to the model.
     * @param string|null      $mimeType    The MIME type for all resources that match this template. This should only be included if all resources matching this template have the same type.
     * @param Annotations|null $annotations optional annotations for the client
     */
    public function __construct(
        public readonly string $uriTemplate,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?Annotations $annotations = null,
    ) {
        if (!preg_match(self::RESOURCE_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException('Invalid resource name: must contain only alphanumeric characters, underscores, and hyphens.');
        }
        if (!preg_match(self::URI_TEMPLATE_PATTERN, $uriTemplate)) {
            throw new InvalidArgumentException('Invalid URI template: must be a valid URI template with at least one placeholder.');
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

        return new self(
            uriTemplate: $data['uriTemplate'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null
        );
    }

    /**
     * @return array{
     *     uriTemplate: string,
     *     name: string,
     *     description?: string,
     *     mimeType?: string,
     *     annotations?: Annotations,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'uriTemplate' => $this->uriTemplate,
            'name' => $this->name,
        ];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }
        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }
        if ($this->annotations instanceof Annotations) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }
}
