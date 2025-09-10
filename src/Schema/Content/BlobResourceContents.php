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

namespace Mcp\Schema\Content;

use SplFileInfo;
use Mcp\Exception\InvalidArgumentException;

/**
 * Represents blob resource contents in MCP.
 *
 * @phpstan-type BlobResourceContentsData array{
 *     uri: string,
 *     mimeType?: string|null,
 *     blob: string
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class BlobResourceContents extends ResourceContents
{
    /**
     * @param string      $uri      the URI of the resource or sub-resource
     * @param string|null $mimeType the MIME type of the resource or sub-resource
     * @param string      $blob     a base64-encoded string representing the binary data of the item
     */
    public function __construct(
        string $uri,
        ?string $mimeType,
        public readonly string $blob,
    ) {
        parent::__construct($uri, $mimeType);
    }

    /**
     * @param BlobResourceContentsData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Missing or invalid "uri" for BlobResourceContents.');
        }
        if (!isset($data['blob']) || !\is_string($data['blob'])) {
            throw new InvalidArgumentException('Missing or invalid "blob" for BlobResourceContents.');
        }

        return new self($data['uri'], $data['mimeType'] ?? null, $data['blob']);
    }

    /**
     * @param resource $stream
     */
    public static function fromStream(string $uri, $stream, string $mimeType): self
    {
        $blob = stream_get_contents($stream);

        return new self($uri, $mimeType, base64_encode($blob));
    }

    public static function fromSplFileInfo(string $uri, SplFileInfo $file, ?string $explicitMimeType = null): self
    {
        $mimeType = $explicitMimeType ?? mime_content_type($file->getPathname());
        $blob = file_get_contents($file->getPathname());

        return new self($uri, $mimeType, base64_encode($blob));
    }

    /**
     * @return BlobResourceContentsData
     */
    public function jsonSerialize(): array
    {
        return [
            'blob' => $this->blob,
            ...$this->jsonSerialize(),
        ];
    }
}
