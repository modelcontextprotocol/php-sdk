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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Annotations;

/**
 * Represents audio content in MCP.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type AudioContentData = array{
 *     type: 'audio',
 *     data: string,
 *     mimeType: string,
 *     annotations?: AnnotationsData,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class AudioContent extends Content
{
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly ?Annotations $annotations = null,
    ) {
        parent::__construct('audio');
    }

    /**
     * @param AudioContentData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['data']) || !isset($data['mimeType'])) {
            throw new InvalidArgumentException('Invalid or missing "data" or "mimeType" in AudioContent data.');
        }

        return new self(
            $data['data'],
            $data['mimeType'],
            isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null
        );
    }

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

    /**
     * @return array{
     *     type: 'audio',
     *     data: string,
     *     mimeType: string,
     *     annotations?: Annotations,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'audio',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        if ($this->annotations instanceof Annotations) {
            $result['annotations'] = $this->annotations;
        }

        return $result;
    }
}
