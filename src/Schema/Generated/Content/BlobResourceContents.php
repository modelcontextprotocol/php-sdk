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

/**
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class BlobResourceContents implements \JsonSerializable
{
    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly string $blob,
        public readonly string $uri,
        public readonly ?string $mimeType = null,
        public readonly ?array $meta = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['blob'])) {
            throw new InvalidArgumentException('Missing "blob" in BlobResourceContents data.');
        }
        if (!\is_string($data['blob'])) {
            throw new InvalidArgumentException('Invalid "blob" in BlobResourceContents data.');
        }
        $blob = $data['blob'];
        if (!isset($data['uri'])) {
            throw new InvalidArgumentException('Missing "uri" in BlobResourceContents data.');
        }
        if (!\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid "uri" in BlobResourceContents data.');
        }
        $uri = $data['uri'];
        $mimeType = null;
        if (isset($data['mimeType'])) {
            if (!\is_string($data['mimeType'])) {
                throw new InvalidArgumentException('Invalid "mimeType" in BlobResourceContents data.');
            }
            $mimeType = $data['mimeType'];
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in BlobResourceContents data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            blob: $blob,
            uri: $uri,
            mimeType: $mimeType,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'blob' => $this->blob,
            'uri' => $this->uri,
        ];

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
