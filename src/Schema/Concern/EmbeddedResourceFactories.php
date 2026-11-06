<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Annotations;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait EmbeddedResourceFactories
{
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

    public static function fromSplFileInfo(string $uri, \SplFileInfo $file, ?string $explicitMimeType = null, ?Annotations $annotations = null): self
    {
        $content = file_get_contents($file->getPathname());
        if (false === $content) {
            throw new RuntimeException(\sprintf('Could not read file: "%s".', $file->getPathname()));
        }

        return new self(new BlobResourceContents($uri, $explicitMimeType ?? (mime_content_type($file->getPathname()) ?: null), base64_encode($content)), $annotations);
    }

    private static function isTextMimeTypeHeuristic(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'text/')
            || \in_array(strtolower($mimeType), ['application/json', 'application/xml', 'application/javascript', 'application/yaml']);
    }
}
