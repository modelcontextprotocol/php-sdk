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
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Annotations;

/**
 * Represents embedded resource content within a message.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 * @phpstan-import-type TextResourceContentsData from TextResourceContents
 * @phpstan-import-type BlobResourceContentsData from BlobResourceContents
 *
 * @phpstan-type EmbeddedResourceData = array{
 *     type: 'resource',
 *     resource: TextResourceContentsData|BlobResourceContentsData,
 *     annotations?: AnnotationsData
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class EmbeddedResource extends Content
{
    public function __construct(
        public readonly TextResourceContents|BlobResourceContents $resource,
        public readonly ?Annotations $annotations = null,
    ) {
        parent::__construct('resource');
    }

    /**
     * @param EmbeddedResourceData $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'resource') {
            throw new InvalidArgumentException('Invalid type for EmbeddedResource.');
        }
        if (!isset($data['resource']) || !\is_array($data['resource'])) {
            throw new InvalidArgumentException('Missing or invalid "resource" field for EmbeddedResource.');
        }

        $resourceData = $data['resource'];
        if (isset($resourceData['text'])) {
            $resourceInstance = TextResourceContents::fromArray($resourceData);
        } elseif (isset($resourceData['blob'])) {
            $resourceInstance = BlobResourceContents::fromArray($resourceData);
        } else {
            throw new InvalidArgumentException('EmbeddedResource "resource" field must contain "text" or "blob".');
        }

        return new self(
            $resourceInstance,
            isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null,
        );
    }

    public static function fromText(string $uri, string $text, ?string $mimeType = 'text/plain', ?Annotations $annotations = null): self
    {
        $textContent = new TextResourceContents($uri, $mimeType, $text);

        return new self($textContent, $annotations);
    }

    public static function fromBlob(string $uri, string $base64Blob, string $mimeType, ?Annotations $annotations = null): self
    {
        $blobContent = new BlobResourceContents($uri, $mimeType, $base64Blob);

        return new self($blobContent, $annotations);
    }

    public static function fromFile(string $uri, string $path, ?string $explicitMimeType = null, ?Annotations $annotations = null): self
    {
        if (!file_exists($path) || !is_readable($path)) {
            throw new InvalidArgumentException(\sprintf('File not found or not readable: "%s".', $path));
        }
        $content = file_get_contents($path);
        if (false === $content) {
            throw new RuntimeException(\sprintf('Could not read file: "%s".', $path));
        }

        $guessedMimeType = $explicitMimeType ?? mime_content_type($path) ?: 'application/octet-stream';

        if (self::isTextMimeTypeHeuristic($guessedMimeType) && mb_check_encoding($content, 'UTF-8')) {
            $resourceContent = new TextResourceContents($uri, $guessedMimeType, $content);
        } else {
            $resourceContent = new BlobResourceContents($uri, $guessedMimeType, base64_encode($content));
        }

        return new self($resourceContent, $annotations);
    }

    /**
     * @param resource $stream
     */
    public static function fromStream(string $uri, $stream, string $mimeType, ?Annotations $annotations = null): self
    {
        $content = stream_get_contents($stream);
        if (false === $content) {
            throw new RuntimeException('Could not read stream.');
        }

        return new self(new BlobResourceContents($uri, $mimeType, base64_encode($content)), $annotations);
    }

    public static function fromSplFileInfo(string $uri, SplFileInfo $file, ?string $explicitMimeType = null, ?Annotations $annotations = null): self
    {
        $content = file_get_contents($file->getPathname());
        if (false === $content) {
            throw new RuntimeException(\sprintf('Could not read file: "%s".', $file->getPathname()));
        }

        return new self(new BlobResourceContents($uri, $explicitMimeType ?? mime_content_type($file->getPathname()), base64_encode($content)), $annotations);
    }

    /**
     * @return array{
     *     type: 'resource',
     *     resource: TextResourceContents|BlobResourceContents,
     *     annotations?: Annotations,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => $this->type,
            'resource' => $this->resource,
        ];
        if ($this->annotations instanceof Annotations) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }

    private static function isTextMimeTypeHeuristic(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || \in_array(strtolower($mimeType), ['application/json', 'application/xml', 'application/javascript', 'application/yaml']);
    }
}
