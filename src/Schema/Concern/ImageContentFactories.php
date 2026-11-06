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
use Mcp\Schema\Annotations;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait ImageContentFactories
{
    /**
     * Create a new ImageContent from a file path.
     *
     * @param string       $path        Path to the image file
     * @param string|null  $mimeType    Optional MIME type override
     * @param ?Annotations $annotations Optional annotations describing the content
     *
     * @throws InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $path, ?string $mimeType = null, ?Annotations $annotations = null): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(\sprintf('Image file not found: "%s".', $path));
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new InvalidArgumentException(\sprintf('Could not read image file: "%s".', $path));
        }

        $data = base64_encode($contents);
        $detectedMime = $mimeType ?? mime_content_type($path) ?: 'image/png';

        return new self($data, $detectedMime, $annotations);
    }

    public static function fromString(string $data, string $mimeType, ?Annotations $annotations = null): self
    {
        return new self(base64_encode($data), $mimeType, $annotations);
    }
}
