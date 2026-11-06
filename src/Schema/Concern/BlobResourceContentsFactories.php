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

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait BlobResourceContentsFactories
{
    /**
     * @param resource              $stream
     * @param ?array<string, mixed> $meta   Optional metadata
     * */
    public static function fromStream(string $uri, $stream, string $mimeType, ?array $meta = null): self
    {
        $blob = stream_get_contents($stream);
        if (false === $blob) {
            throw new InvalidArgumentException('Could not read stream.');
        }

        return new self($uri, $mimeType, base64_encode($blob), $meta);
    }

    /**
     * @param ?array<string, mixed> $meta Optional metadata
     * */
    public static function fromSplFileInfo(string $uri, \SplFileInfo $file, ?string $explicitMimeType = null, ?array $meta = null): self
    {
        $mimeType = $explicitMimeType ?? (mime_content_type($file->getPathname()) ?: null);
        $blob = file_get_contents($file->getPathname());
        if (false === $blob) {
            throw new InvalidArgumentException(\sprintf('Could not read file: "%s".', $file->getPathname()));
        }

        return new self($uri, $mimeType, base64_encode($blob), $meta);
    }
}
