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

use Mcp\Schema\Annotations;
use Mcp\Schema\Exception\InvalidArgumentException;

/**
 * Represents text content in MCP.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type TextContentData array{
 *     type: 'text',
 *     text: string,
 *     annotations?: AnnotationsData,
 *     _meta?: array<string, mixed>,
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class TextContent extends Content
{
    /**
     * Create a new TextContent instance from any value.
     *
     * @param mixed                 $text        The value to convert to text
     * @param ?Annotations          $annotations Optional annotations describing the content
     * @param ?array<string, mixed> $meta        Optional metadata
     */
    public function __construct(
        public mixed $text,
        public readonly ?Annotations $annotations = null,
        public readonly ?array $meta = null,
    ) {
        $this->text = (\is_array($text) || \is_object($text))
            ? json_encode($text) : (string) $text;

        parent::__construct('text');
    }

    /**
     * @param TextContentData|array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['text']) || !\is_string($data['text'])) {
            throw new InvalidArgumentException('Missing or invalid "text" in TextContent data.');
        }
        if (isset($data['_meta']) && !\is_array($data['_meta'])) {
            throw new InvalidArgumentException('Invalid "_meta" in TextContent data.');
        }

        return new self(
            $data['text'],
            Annotations::tryFromArray($data['annotations'] ?? null, 'TextContent'),
            $data['_meta'] ?? null,
        );
    }

    /**
     * Create a new TextContent with markdown formatted code.
     *
     * @param string $code     The code to format
     * @param string $language The language for syntax highlighting
     */
    public static function code(string $code, string $language = '', ?Annotations $annotations = null): self
    {
        return new self("```{$language}\n{$code}\n```", $annotations);
    }

    /**
     * Convert the content to an array.
     *
     * @return array{
     *     type: 'text',
     *     text: string,
     *     annotations?: Annotations,
     *     _meta?: array<string, mixed>,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'type' => 'text',
            'text' => $this->text,
        ];

        if (null !== $this->annotations) {
            $result['annotations'] = $this->annotations;
        }
        if (null !== $this->meta) {
            $result['_meta'] = $this->meta;
        }

        return $result;
    }
}
