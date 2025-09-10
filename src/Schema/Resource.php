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
 * A known resource that the server is capable of reading.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type ResourceData array{
 *     uri: string,
 *     name: string,
 *     description?: string|null,
 *     mimeType?: string|null,
 *     annotations?: AnnotationsData|null,
 *     size?: int|null,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Resource implements JsonSerializable
{
    /**
     * Resource name pattern regex - must contain only alphanumeric characters, underscores, and hyphens.
     */
    private const RESOURCE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    /**
     * URI pattern regex - requires a valid scheme, followed by colon and optional path.
     * Example patterns: config://, file://path, db://table, etc.
     */
    private const URI_PATTERN = '/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\s]*$/';

    /**
     * @param string           $uri         the URI of this resource
     * @param string           $name        A human-readable name for this resource. This can be used by clients to populate UI elements.
     * @param string|null      $description A description of what this resource represents. This can be used by clients to improve the LLM's understanding of available resources. It can be thought of like a "hint" to the model.
     * @param string|null      $mimeType    the MIME type of this resource, if known
     * @param Annotations|null $annotations optional annotations for the client
     * @param int|null         $size        The size of the raw resource content, in bytes (i.e., before base64 encoding or any tokenization), if known.
     *
     * This can be used by Hosts to display file sizes and estimate context window usage.
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?Annotations $annotations = null,
        public readonly ?int $size = null,
    ) {
        if (!preg_match(self::RESOURCE_NAME_PATTERN, $name)) {
            throw new InvalidArgumentException('Invalid resource name: must contain only alphanumeric characters, underscores, and hyphens.');
        }
        if (!preg_match(self::URI_PATTERN, $uri)) {
            throw new InvalidArgumentException('Invalid resource URI: must be a valid URI with a scheme and optional path.');
        }
    }

    /**
     * @param ResourceData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" in Resource data.');
        }
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in Resource data.');
        }

        return new self(
            uri: $data['uri'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null,
            size: $data['size'] ?? null
        );
    }

    /**
     * @return array{
     *     uri: string,
     *     name: string,
     *     description?: string,
     *     mimeType?: string,
     *     annotations?: Annotations,
     *     size?: int,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'uri' => $this->uri,
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
        if (null !== $this->size) {
            $data['size'] = $this->size;
        }

        return $data;
    }
}
