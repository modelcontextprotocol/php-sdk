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
 * Represents text content in MCP.
 *
 * @phpstan-import-type AnnotationsData from Annotations
 *
 * @phpstan-type TextContentData array{
 *     type: 'text',
 *     text: string,
 *     annotations?: AnnotationsData,
 *     isError: bool
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class TextContent extends Content
{
    /**
     * Create a new TextContent instance from any value.
     *
     * @param mixed        $text        The value to convert to text
     * @param ?Annotations $annotations Optional annotations describing the content
     * @param bool         $isError     Optional, mark response as error
     */
    public function __construct(
        public mixed $text,
        public readonly ?Annotations $annotations = null,
        public readonly bool $isError = false,
    ) {
        $this->text = (\is_array($text) || \is_object($text))
            ? json_encode($text, \JSON_PRETTY_PRINT) : (string) $text;

        parent::__construct('text');
    }

    /**
     * @param TextContentData $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['text']) || !\is_string($data['text'])) {
            throw new InvalidArgumentException('Missing or invalid "text" in TextContent data.');
        }

        return new self(
            $data['text'],
            isset($data['annotations']) ? Annotations::fromArray($data['annotations']) : null
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

        return $result;
    }
}
