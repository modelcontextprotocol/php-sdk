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

/**
 * @phpstan-type ImageContentData array{
 *     type: 'image',
 *     data: string,
 *     mimeType: string
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ImageContent extends Content
{
    /**
     * Create a new ImageContent instance.
     *
     * @param string $data     Base64-encoded image data
     * @param string $mimeType The MIME type of the image
     */
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
    ) {
        parent::__construct('image');
    }

    /**
     * @param ImageContentData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['data']) || !\is_string($data['data'])) {
            throw new InvalidArgumentException('Missing or invalid "data" in ImageContent data.');
        }
        if (!isset($data['mimeType']) || !\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Missing or invalid "mimeType" in ImageContent data.');
        }

        return new self($data['data'], $data['mimeType']);
    }

    /**
     * Create a new ImageContent from a file path.
     *
     * @param string      $path     Path to the image file
     * @param string|null $mimeType Optional MIME type override
     *
     * @throws InvalidArgumentException If the file doesn't exist
     */
    public static function fromFile(string $path, ?string $mimeType = null): self
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException(\sprintf('Image file not found: "%s".', $path));
        }

        $data = base64_encode(file_get_contents($path));
        $detectedMime = $mimeType ?? mime_content_type($path) ?: 'image/png';

        return new self($data, $detectedMime);
    }

    public static function fromString(string $data, string $mimeType): self
    {
        return new self(base64_encode($data), $mimeType);
    }

    /**
     * Convert the content to an array.
     *
     * @return ImageContentData
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->type,
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];
    }
}
