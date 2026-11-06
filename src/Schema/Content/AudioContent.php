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
use Mcp\Schema\Concern\AudioContentFactories;

/**
 * Audio provided to or from an LLM.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type AudioContentData array{
 *     data: string,
 *     mimeType: string,
 *     type: 'audio',
 *     annotations?: AnnotationsData,
 *     _meta?: array<string, mixed>,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class AudioContent implements ContentBlock, SamplingMessageContentBlock
{
    use AudioContentFactories;

    public const TYPE = 'audio';

    public readonly string $type;

    /**
     * @param array<string, mixed>|null $meta since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly string $data,
        public readonly string $mimeType,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
        $this->type = self::TYPE;
        if (null !== $this->meta && [] !== $this->meta && array_is_list($this->meta)) {
            throw new InvalidArgumentException('AudioContent "_meta" must be a map, not a list.');
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['data'])) {
            throw new InvalidArgumentException('Missing "data" in AudioContent data.');
        }
        if (!\is_string($data['data'])) {
            throw new InvalidArgumentException('Invalid "data" in AudioContent data.');
        }
        $dataValue = $data['data'];
        if (!isset($data['mimeType'])) {
            throw new InvalidArgumentException('Missing "mimeType" in AudioContent data.');
        }
        if (!\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Invalid "mimeType" in AudioContent data.');
        }
        $mimeType = $data['mimeType'];
        if (isset($data['type']) && self::TYPE !== $data['type']) {
            throw new InvalidArgumentException('Invalid "type" in AudioContent data.');
        }
        $annotations = null;
        if (isset($data['annotations'])) {
            if (!\is_array($data['annotations'])) {
                throw new InvalidArgumentException('Invalid "annotations" in AudioContent data.');
            }
            $annotations = Annotations::fromArray($data['annotations']);
        }
        $meta = null;
        if (isset($data['_meta'])) {
            if (!\is_array($data['_meta'])) {
                throw new InvalidArgumentException('Invalid "_meta" in AudioContent data.');
            }
            $meta = $data['_meta'];
        }

        return new self(
            data: $dataValue,
            mimeType: $mimeType,
            annotations: $annotations,
            meta: $meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'type' => self::TYPE,
            'data' => $this->data,
            'mimeType' => $this->mimeType,
        ];

        if (null !== $this->annotations) {
            $data['annotations'] = $this->annotations;
        }

        if (null !== $this->meta) {
            $data['_meta'] = [] === $this->meta ? new \stdClass() : $this->meta;
        }

        return $data;
    }
}
