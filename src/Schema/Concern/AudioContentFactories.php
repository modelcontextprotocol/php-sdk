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

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait AudioContentFactories
{
    /**
     * Create a new AudioContent from a file path.
     *
     * @param string       $path        Path to the audio file
     * @param string|null  $mimeType    Optional MIME type override
     * @param ?Annotations $annotations Optional annotations describing the content
     *
     * @throws InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $path, ?string $mimeType = null, ?Annotations $annotations = null): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(\sprintf('Audio file not found: "%s".', $path));
        }

        $content = file_get_contents($path);
        if (false === $content) {
            throw new RuntimeException(\sprintf('Could not read audio file: "%s".', $path));
        }
        $data = base64_encode($content);
        $detectedMime = $mimeType ?? mime_content_type($path) ?: 'application/octet-stream';

        return new self($data, $detectedMime, $annotations);
    }

    /**
     * Create a new AudioContent from a string.
     *
     * @param string       $data        The audio data
     * @param string       $mimeType    MIME type of the audio
     * @param ?Annotations $annotations Optional annotations describing the content
     */
    public static function fromString(string $data, string $mimeType, ?Annotations $annotations = null): self
    {
        return new self(base64_encode($data), $mimeType, $annotations);
    }
}
