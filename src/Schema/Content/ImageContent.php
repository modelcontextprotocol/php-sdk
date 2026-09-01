<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Content;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Annotations;

/**
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type ImageContentData array{
 *     type: 'image',
 *     data: string,
 *     mimeType: string,
 *     annotations?: AnnotationsData,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ImageContent extends Content
{
    /**
     * Create a new ImageContent instance.
     *
     * @param string       $data        Base64-encoded image data
     * @param string       $mimeType    The MIME type of the image
     * @param ?Annotations $annotations Optional annotations describing the content
     */
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly ?Annotations $annotations = null,
    ) {
        parent::__construct('image');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['data']) || !\is_string($data['data'])) {
            throw new InvalidArgumentException('Missing or invalid "data" in ImageContent data.');
        }
        if (!isset($data['mimeType']) || !\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Missing or invalid "mimeType" in ImageContent data.');
        }

        return new self(
            $data['data'],
            $data['mimeType'],
            isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null
        );
    }

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

    /**
     * Convert the content to an array.
     *
     * @return array{
     *     type: 'image',
     *     data: string,
     *     mimeType: string,
     *     annotations?: Annotations,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'image',
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        if (null !== $this->annotations) {
            $result['annotations'] = $this->annotations;
        }

        return $result;
    }
}
